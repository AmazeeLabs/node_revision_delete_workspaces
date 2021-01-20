<?php


namespace Drupal\node_revision_delete_workspaces\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\workspaces\WorkspaceManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class RevisionDeleteLocalTasks extends DeriverBase implements ContainerDeriverInterface {
  use StringTranslationTrait;

  /**
   * The base plugin ID
   *
   * @var string
   */
  protected $basePluginId;

  /**
   * The workspace manager service.
   *
   * @var WorkspaceManagerInterface $workspaceManager
   */
  protected $workspaceManager;

  /**
   * Constructs a new RevisionDeleteLocalTasks.
   *
   */
  public function __construct($base_plugin_id, WorkspaceManagerInterface $workspaceManager) {
    $this->basePluginId = $base_plugin_id;
    $this->workspaceManager = $workspaceManager;
  }

  /**
   * Creates a new class instance.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container to pull out services used in the fetcher.
   * @param string $base_plugin_id
   *   The base plugin ID for the plugin ID.
   *
   * @return static
   *   Returns an instance of this fetcher.
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $base_plugin_id,
      $container->get('workspaces.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    // Create tabs for all the entity types which have workspace support.
    foreach ($this->workspaceManager->getSupportedEntityTypes() as $entity_type_id => $entity_type) {
      $revisions_cleanup_route_name = "entity.$entity_type_id.revisions_cleanup";

      $base_route_name = "entity.$entity_type_id.canonical";
      $this->derivatives[$revisions_cleanup_route_name] = [
          'entity_type' => $entity_type_id,
          'title' => $this->t('Revisions cleanup'),
          'route_name' => $revisions_cleanup_route_name,
          'base_route' => $base_route_name,
        ] + $base_plugin_definition;
    }
    return parent::getDerivativeDefinitions($base_plugin_definition);
  }
}
