<?php

declare(strict_types=1);

namespace Drupal\jsonapi_frontend\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\jsonapi_frontend\Event\HeadlessContentChangedEvent;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Sends cache revalidation webhooks to the frontend.
 *
 * When headless content changes, this subscriber sends a POST request
 * to the configured webhook URL with the affected paths and cache tags.
 * This allows the frontend to instantly invalidate its cache.
 *
 * The webhook is fire-and-forget with a short timeout to avoid blocking
 * entity operations. Failures are logged but don't prevent content saving.
 */
final class RevalidationSubscriber implements EventSubscriberInterface {

  /**
   * Timeout for webhook requests in seconds.
   */
  private const WEBHOOK_TIMEOUT = 5;

  /**
   * Connection timeout in seconds.
   */
  private const CONNECT_TIMEOUT = 2;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      HeadlessContentChangedEvent::EVENT_NAME => ['onContentChanged', 0],
    ];
  }

  /**
   * Handle content changed event by sending webhook.
   */
  public function onContentChanged(HeadlessContentChangedEvent $event): void {
    $config = $this->configFactory->get('jsonapi_frontend.settings');

    // Check if revalidation is enabled.
    if (!$config->get('revalidation.enabled')) {
      return;
    }

    $webhook_url = $config->get('revalidation.url');
    if (empty($webhook_url)) {
      return;
    }

    // Validate URL format and security.
    if (!$this->isValidWebhookUrl($webhook_url)) {
      $this->logger->error('Invalid or insecure revalidation webhook URL: @url', [
        '@url' => $webhook_url,
      ]);
      return;
    }

    $secret = $config->get('revalidation.secret') ?: '';

    // Build the payload.
    $payload = [
      'operation' => $event->getOperation(),
      'paths' => $event->getPaths(),
      'tags' => $event->getTags(),
      'entity' => [
        'type' => $event->getEntityTypeId(),
        'bundle' => $event->getBundle(),
        'uuid' => $event->getUuid(),
      ],
      'timestamp' => time(),
    ];

    // Send the webhook asynchronously (fire-and-forget).
    $this->sendWebhook($webhook_url, $secret, $payload);
  }

  /**
   * Validates webhook URL format and prevents SSRF attacks.
   *
   * Rejects URLs pointing to private/internal networks to prevent
   * Server-Side Request Forgery attacks.
   */
  private function isValidWebhookUrl(string $url): bool {
    // Must be a valid URL.
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      return FALSE;
    }

    // Must use HTTPS in production (allow HTTP for local development).
    $parsed = parse_url($url);
    if (!$parsed || !isset($parsed['host'])) {
      return FALSE;
    }

    $host = strtolower($parsed['host']);
    $scheme = strtolower($parsed['scheme'] ?? '');

    // Only allow http/https schemes.
    if (!in_array($scheme, ['http', 'https'], TRUE)) {
      return FALSE;
    }

    // Block localhost and loopback.
    $blocked_hosts = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];
    if (in_array($host, $blocked_hosts, TRUE)) {
      return FALSE;
    }

    // Block private/reserved ranges for both IPv4 and IPv6.
    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

    // If the host is already an IP (v4 or v6), validate directly.
    if (filter_var($host, FILTER_VALIDATE_IP)) {
      return (bool) filter_var($host, FILTER_VALIDATE_IP, $flags);
    }

    // Resolve host to A/AAAA records and validate each IP.
    if (!function_exists('dns_get_record')) {
      $ips = gethostbynamel($host);
      if (empty($ips)) {
        return FALSE;
      }

      foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
          return FALSE;
        }
      }

      return TRUE;
    }

    $records = dns_get_record($host, DNS_A | DNS_AAAA);
    if ($records === FALSE || empty($records)) {
      return FALSE;
    }

    foreach ($records as $record) {
      $ip = $record['ip'] ?? $record['ipv6'] ?? NULL;
      if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Send the webhook request.
   *
   * Uses a short timeout and catches all exceptions to avoid blocking
   * entity operations. Failures are logged for debugging.
   */
  private function sendWebhook(string $url, string $secret, array $payload): void {
    try {
      $headers = [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'User-Agent' => 'Drupal/jsonapi_frontend',
      ];

      // Add secret header if configured (using timing-safe header name).
      if (!empty($secret)) {
        $headers['X-Revalidation-Secret'] = $secret;
      }

      // Send request with short timeout.
      // We use a synchronous request with short timeout rather than async
      // because Guzzle's async requires explicit waiting or the request
      // may not complete before PHP shuts down.
      $response = $this->httpClient->request('POST', $url, [
        'headers' => $headers,
        'json' => $payload,
        'timeout' => self::WEBHOOK_TIMEOUT,
        'connect_timeout' => self::CONNECT_TIMEOUT,
        // Don't throw exceptions for 4xx/5xx responses.
        'http_errors' => FALSE,
      ]);

      $status = $response->getStatusCode();

      if ($status >= 200 && $status < 300) {
        $this->logger->info('Revalidation webhook sent successfully for @operation: @tags', [
          '@operation' => $payload['operation'],
          '@tags' => implode(', ', array_slice($payload['tags'], 0, 5)),
        ]);
      }
      else {
        $this->logger->warning('Revalidation webhook returned @status for @operation: @tags', [
          '@status' => $status,
          '@operation' => $payload['operation'],
          '@tags' => implode(', ', array_slice($payload['tags'], 0, 5)),
        ]);
      }
    }
    catch (GuzzleException $e) {
      // Log but don't throw - we don't want to break entity operations.
      $this->logger->error('Revalidation webhook failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
    catch (\Exception $e) {
      // Catch any other exceptions.
      $this->logger->error('Revalidation webhook error: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
