<?php

declare(strict_types=1);

namespace Drupal\jsonapi_frontend\Form;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for JSON:API Frontend settings.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  public static function create(ContainerInterface $container): self {
    $instance = new self(
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('module_handler'),
    );
    $instance->setConfigFactory($container->get('config.factory'));
    $instance->setMessenger($container->get('messenger'));
    return $instance;
  }

  public function getFormId(): string {
    return 'jsonapi_frontend_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['jsonapi_frontend.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('jsonapi_frontend.settings');

    $form['intro'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Configure which content should be rendered by your headless frontend (e.g., Next.js). Unchecked items will include a <code>drupal_url</code> in the resolver response, allowing the frontend to redirect or proxy to Drupal. This enables <strong>sequential migration</strong> — move content types one at a time while keeping others on Drupal.') . '</p>',
    ];

    // --- Deployment Mode ---
    $form['deployment'] = [
      '#type' => 'details',
      '#title' => $this->t('Deployment Mode'),
      '#open' => TRUE,
    ];

    $form['deployment']['deployment_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('How is traffic routed?'),
      '#options' => [
        'split_routing' => $this->t('<strong>Split Routing</strong> — Drupal stays on main domain'),
        'nextjs_first' => $this->t('<strong>Next.js First</strong> — Frontend on main domain (recommended)'),
      ],
      '#default_value' => $config->get('deployment_mode') ?: 'split_routing',
      '#description' => $this->t('Choose based on your infrastructure. Both support gradual migration.'),
    ];

    $form['deployment']['split_routing_info'] = [
      '#type' => 'markup',
      '#markup' => '<div class="description" style="margin-left: 2em; margin-bottom: 1em;"><em>' . $this->t('Split Routing: External router (Cloudflare, nginx) directs specific paths to your frontend. No DNS change required. <a href="@url">See documentation</a>.', ['@url' => 'https://www.drupal.org/docs/contributed-modules/jsonapi-frontend']) . '</em></div>',
      '#states' => [
        'visible' => [
          ':input[name="deployment_mode"]' => ['value' => 'split_routing'],
        ],
      ],
    ];

    $form['deployment']['nextjs_first_info'] = [
      '#type' => 'markup',
      '#markup' => '<div class="description" style="margin-left: 2em; margin-bottom: 1em;"><em>' . $this->t('Next.js First: Frontend handles all traffic, proxies non-headless content to Drupal origin. Best performance. Requires DNS change.') . '</em></div>',
      '#states' => [
        'visible' => [
          ':input[name="deployment_mode"]' => ['value' => 'nextjs_first'],
        ],
      ],
    ];

    $form['deployment']['drupal_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Drupal URL'),
      '#description' => $this->t('For Split Routing: URL for <code>drupal_url</code> responses. For Next.js First: Drupal origin URL for proxying. Leave empty to use current site URL.'),
      '#default_value' => $config->get('drupal_base_url') ?: '',
      '#placeholder' => 'https://cms.example.com',
    ];

    $form['deployment']['proxy_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Proxy Secret'),
      '#description' => $this->t('Shared secret for origin protection. Frontend sends this as <code>X-Proxy-Secret</code> header. Drupal rejects requests without it. <strong>Required for production Next.js First deployments.</strong> Leave empty to keep current value.'),
      '#placeholder' => $config->get('proxy_secret') ? $this->t('(secret configured - leave empty to keep)') : $this->t('Leave empty to auto-generate'),
      '#states' => [
        'visible' => [
          ':input[name="deployment_mode"]' => ['value' => 'nextjs_first'],
        ],
      ],
    ];

    $form['deployment']['generate_secret'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Generate new proxy secret on save'),
      '#default_value' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="deployment_mode"]' => ['value' => 'nextjs_first'],
        ],
      ],
    ];

    // --- Resolver Configuration ---
    $form['resolver'] = [
      '#type' => 'details',
      '#title' => $this->t('Resolver Endpoint'),
      '#open' => TRUE,
    ];

    $form['resolver']['resolver_cache_max_age'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache max-age (seconds)'),
      '#description' => $this->t('Controls caching for <code>/jsonapi/resolve</code>. Set to 0 to disable caching (Cache-Control: <code>no-store</code>). For safety, caching is only applied to anonymous requests.'),
      '#default_value' => (int) ($config->get('resolver.cache_max_age') ?? 0),
      '#min' => 0,
      '#step' => 1,
    ];

    $form['resolver']['resolver_langcode_fallback'] = [
      '#type' => 'radios',
      '#title' => $this->t('Default language (when langcode is omitted)'),
      '#description' => $this->t('When <code>langcode</code> is not provided, choose which language should be used to resolve path aliases.'),
      '#options' => [
        'site_default' => $this->t('Site default language (deterministic)'),
        'current' => $this->t('Current (negotiated) content language'),
      ],
      '#default_value' => $config->get('resolver.langcode_fallback') ?: 'site_default',
    ];

    // --- Entity Configuration ---
    $form['entities'] = [
      '#type' => 'details',
      '#title' => $this->t('Entity Types'),
      '#open' => TRUE,
    ];

    $form['entities']['enable_all'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable all entity types for headless'),
      '#description' => $this->t('When checked, all entity types are rendered by the frontend. Uncheck to select specific types below.'),
      '#default_value' => $config->get('enable_all') ?? TRUE,
    ];

    $form['entities']['bundles_container'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="enable_all"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['entities']['bundles_container']['headless_bundles'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Headless-enabled entity types'),
      '#description' => $this->t('Select which entity types should be rendered by the headless frontend.'),
      '#tree' => TRUE,
    ];

    $enabled_bundles = $config->get('headless_bundles') ?: [];
    $enabled_bundles_map = array_flip($enabled_bundles);

    foreach ($this->getSupportedEntityTypeIds() as $entity_type_id) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
      if (!$entity_type) {
        continue;
      }

      $bundles = $this->bundleInfo->getBundleInfo($entity_type_id);
      if (empty($bundles)) {
        $bundles = [
          $entity_type_id => [
            'label' => $this->t('Default'),
          ],
        ];
      }

      $open = in_array($entity_type_id, ['node', 'taxonomy_term', 'media', 'user'], TRUE);

      $form['entities']['bundles_container']['headless_bundles'][$entity_type_id] = [
        '#type' => 'details',
        '#title' => $entity_type->getLabel(),
        '#open' => $open,
      ];

      foreach ($bundles as $bundle_id => $bundle_info) {
        $key = "{$entity_type_id}:{$bundle_id}";
        $form['entities']['bundles_container']['headless_bundles'][$entity_type_id][$bundle_id] = [
          '#type' => 'checkbox',
          '#title' => $bundle_info['label'],
          '#default_value' => isset($enabled_bundles_map[$key]),
        ];
      }
    }

    // --- Views Configuration ---
    $form['views'] = [
      '#type' => 'details',
      '#title' => $this->t('Views'),
      '#open' => TRUE,
    ];

    $jsonapi_views_installed = $this->moduleHandler->moduleExists('jsonapi_views');

    if (!$jsonapi_views_installed) {
      $form['views']['notice'] = [
        '#type' => 'markup',
        '#markup' => '<p><em>' . $this->t('Views support requires the <a href="@url">jsonapi_views</a> module to be installed.', [
          '@url' => 'https://www.drupal.org/project/jsonapi_views',
        ]) . '</em></p>',
      ];
    }
    else {
      $form['views']['enable_all_views'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable all Views for headless'),
        '#description' => $this->t('When checked, all Views with page displays are rendered by the frontend. Uncheck to select specific Views below.'),
        '#default_value' => $config->get('enable_all_views') ?? TRUE,
      ];

      $form['views']['views_container'] = [
        '#type' => 'container',
        '#states' => [
          'visible' => [
            ':input[name="enable_all_views"]' => ['checked' => FALSE],
          ],
        ],
      ];

      $form['views']['views_container']['headless_views'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Headless-enabled Views'),
        '#description' => $this->t('Select which Views should be rendered by the headless frontend.'),
        '#tree' => TRUE,
      ];

      $enabled_views = $config->get('headless_views') ?: [];
      $enabled_views_map = array_flip($enabled_views);

      // Get all views with page displays.
      $views = $this->getViewsWithPageDisplays();

      if (empty($views)) {
        $form['views']['views_container']['headless_views']['empty'] = [
          '#type' => 'markup',
          '#markup' => '<p><em>' . $this->t('No Views with page displays found.') . '</em></p>',
        ];
      }
      else {
        foreach ($views as $view_id => $view_info) {
          $form['views']['views_container']['headless_views'][$view_id] = [
            '#type' => 'fieldset',
            '#title' => $view_info['label'],
            '#collapsible' => FALSE,
          ];

          foreach ($view_info['displays'] as $display_id => $display_label) {
            $key = "{$view_id}:{$display_id}";
            $form['views']['views_container']['headless_views'][$view_id][$display_id] = [
              '#type' => 'checkbox',
              '#title' => $display_label,
              '#default_value' => isset($enabled_views_map[$key]),
            ];
          }
        }
      }
    }

    // --- Cache Revalidation ---
    $form['revalidation'] = [
      '#type' => 'details',
      '#title' => $this->t('Cache Revalidation'),
      '#open' => TRUE,
    ];

    $form['revalidation']['revalidation_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable cache revalidation webhooks'),
      '#description' => $this->t('When enabled, Drupal will send a webhook to your frontend when headless content changes, allowing instant cache invalidation.'),
      '#default_value' => $config->get('revalidation.enabled') ?? FALSE,
    ];

    $form['revalidation']['revalidation_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Revalidation webhook URL'),
      '#description' => $this->t('The URL of your frontend revalidation endpoint (e.g., <code>https://your-frontend.com/api/revalidate</code>).'),
      '#default_value' => $config->get('revalidation.url') ?: '',
      '#placeholder' => 'https://example.com/api/revalidate',
      '#states' => [
        'visible' => [
          ':input[name="revalidation_enabled"]' => ['checked' => TRUE],
        ],
        'required' => [
          ':input[name="revalidation_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['revalidation']['revalidation_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Revalidation secret'),
      '#description' => $this->t('A shared secret to authenticate webhook requests. Leave empty to keep current value, or enter a new secret.'),
      '#placeholder' => $config->get('revalidation.secret') ? $this->t('(secret configured - leave empty to keep)') : $this->t('Enter a secret'),
      '#states' => [
        'visible' => [
          ':input[name="revalidation_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['revalidation']['generate_revalidation_secret'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Generate new revalidation secret on save'),
      '#default_value' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="revalidation_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['revalidation']['webhook_info'] = [
      '#type' => 'details',
      '#title' => $this->t('Webhook payload format'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="revalidation_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['revalidation']['webhook_info']['payload_example'] = [
      '#type' => 'markup',
      '#markup' => '<pre><code>{
  "operation": "update",
  "paths": ["/about-us"],
  "tags": ["drupal", "type:node--page", "bundle:page", "node:uuid", "uuid:uuid"],
  "entity": {
    "type": "node",
    "bundle": "page",
    "uuid": "550e8400-e29b-41d4-a716-446655440000"
  },
  "timestamp": 1704067200
}</code></pre>
<p>' . $this->t('The webhook sends a POST request with <code>X-Revalidation-Secret</code> header. Your frontend should validate this header and call <code>revalidateTag()</code> for each tag.') . '</p>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Get supported entity type IDs for headless routing.
   *
   * This is limited to content entity types that have canonical routes.
   *
   * @return string[]
   *   Entity type IDs.
   */
  private function getSupportedEntityTypeIds(): array {
    $entity_type_ids = [];

    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $definition) {
      if (!$definition->entityClassImplements(ContentEntityInterface::class)) {
        continue;
      }
      if (!$definition->hasLinkTemplate('canonical')) {
        continue;
      }
      $entity_type_ids[] = $entity_type_id;
    }

    sort($entity_type_ids);

    return $entity_type_ids;
  }

  /**
   * Get all Views with page displays.
   *
   * @return array
   *   Array of views keyed by view_id with 'label' and 'displays' keys.
   */
  private function getViewsWithPageDisplays(): array {
    $views = [];

    try {
      $view_storage = $this->entityTypeManager->getStorage('view');
      $all_views = $view_storage->loadMultiple();

      foreach ($all_views as $view) {
        /** @var \Drupal\views\ViewEntityInterface $view */
        if (!$view->status()) {
          continue;
        }

        $displays = $view->get('display');
        $page_displays = [];

        foreach ($displays as $display_id => $display) {
          if (isset($display['display_plugin']) && $display['display_plugin'] === 'page') {
            $display_title = $display['display_title'] ?? $display_id;
            $path = $display['display_options']['path'] ?? '';
            $label = $path ? "{$display_title} (/{$path})" : $display_title;
            $page_displays[$display_id] = $label;
          }
        }

        if (!empty($page_displays)) {
          $views[$view->id()] = [
            'label' => $view->label(),
            'displays' => $page_displays,
          ];
        }
      }
    }
    catch (\Exception $e) {
      // Views module might not be fully available.
      \Drupal::logger('jsonapi_frontend')->warning('Could not load views: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $views;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('jsonapi_frontend.settings');

    // --- Deployment configuration ---
    $config->set('deployment_mode', $form_state->getValue('deployment_mode') ?: 'split_routing');
    $config->set('drupal_base_url', $form_state->getValue('drupal_base_url') ?: '');

    // Handle proxy secret (password field - keep existing if empty)
    $proxy_secret = $form_state->getValue('proxy_secret') ?: '';
    $current_secret = $config->get('proxy_secret') ?: '';
    $generate_new = $form_state->getValue('generate_secret');

    if ($generate_new) {
      // Explicitly requested new secret
      $proxy_secret = bin2hex(random_bytes(32));
      $this->messenger()->addStatus($this->t('New proxy secret generated. Copy it to your frontend environment variables: <code>DRUPAL_PROXY_SECRET=@secret</code>', [
        '@secret' => $proxy_secret,
      ]));
    }
    elseif (empty($proxy_secret) && !empty($current_secret)) {
      // Keep existing secret if field was left empty
      $proxy_secret = $current_secret;
    }
    elseif ($form_state->getValue('deployment_mode') === 'nextjs_first' && empty($proxy_secret)) {
      // Auto-generate if enabling nextjs_first mode without a secret
      $proxy_secret = bin2hex(random_bytes(32));
      $this->messenger()->addStatus($this->t('Proxy secret generated. Copy it to your frontend environment variables: <code>DRUPAL_PROXY_SECRET=@secret</code>', [
        '@secret' => $proxy_secret,
      ]));
    }

    $config->set('proxy_secret', $proxy_secret);

    // --- Resolver configuration ---
    $resolver_cache_max_age = (int) $form_state->getValue('resolver_cache_max_age');
    if ($resolver_cache_max_age < 0) {
      $resolver_cache_max_age = 0;
    }
    $config->set('resolver.cache_max_age', $resolver_cache_max_age);

    $langcode_fallback = (string) ($form_state->getValue('resolver_langcode_fallback') ?: 'site_default');
    if (!in_array($langcode_fallback, ['site_default', 'current'], TRUE)) {
      $langcode_fallback = 'site_default';
    }
    $config->set('resolver.langcode_fallback', $langcode_fallback);

    // --- Entity configuration ---
    $config->set('enable_all', (bool) $form_state->getValue('enable_all'));

    // Build the list of enabled bundles.
    $headless_bundles = [];
    $bundles_values = $form_state->getValue('headless_bundles') ?: [];

    foreach ($bundles_values as $entity_type_id => $bundles) {
      if (!is_array($bundles)) {
        continue;
      }
      foreach ($bundles as $bundle_id => $enabled) {
        if ($enabled) {
          $headless_bundles[] = "{$entity_type_id}:{$bundle_id}";
        }
      }
    }

    $config->set('headless_bundles', $headless_bundles);

    // --- Views configuration ---
    $config->set('enable_all_views', (bool) $form_state->getValue('enable_all_views'));

    // Build the list of enabled views.
    $headless_views = [];
    $views_values = $form_state->getValue('headless_views') ?: [];

    foreach ($views_values as $view_id => $displays) {
      if (!is_array($displays)) {
        continue;
      }
      foreach ($displays as $display_id => $enabled) {
        if ($enabled) {
          $headless_views[] = "{$view_id}:{$display_id}";
        }
      }
    }

    $config->set('headless_views', $headless_views);

    // --- Revalidation configuration ---
    $config->set('revalidation.enabled', (bool) $form_state->getValue('revalidation_enabled'));
    $config->set('revalidation.url', $form_state->getValue('revalidation_url') ?: '');

    // Handle revalidation secret
    $revalidation_secret = $form_state->getValue('revalidation_secret') ?: '';
    $generate_revalidation = $form_state->getValue('generate_revalidation_secret');
    $current_secret = $config->get('revalidation.secret') ?: '';

    if ($generate_revalidation) {
      // Generate a secure random secret
      $revalidation_secret = bin2hex(random_bytes(32));
      $this->messenger()->addStatus($this->t('New revalidation secret generated. Copy it to your frontend environment variables: <code>REVALIDATION_SECRET=@secret</code>', [
        '@secret' => $revalidation_secret,
      ]));
    }
    elseif (empty($revalidation_secret) && !empty($current_secret)) {
      // Keep existing secret if field was left empty
      $revalidation_secret = $current_secret;
    }
    elseif ($form_state->getValue('revalidation_enabled') && empty($revalidation_secret) && empty($current_secret)) {
      // Auto-generate if enabling but no secret exists
      $revalidation_secret = bin2hex(random_bytes(32));
      $this->messenger()->addStatus($this->t('Revalidation secret generated. Copy it to your frontend environment variables: <code>REVALIDATION_SECRET=@secret</code>', [
        '@secret' => $revalidation_secret,
      ]));
    }

    $config->set('revalidation.secret', $revalidation_secret);

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
