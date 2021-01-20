<?php

namespace Drupal\node_revision_delete_workspaces\Routing;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\workspaces\WorkspaceManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class RevisionsCleanupRoutes implements ContainerInjectionInterface {

  /**
   * The workspace manager service.
   *
   * @var WorkspaceManagerInterface $workspaceManager
   */
  protected $workspaceManager;

  /**
   * RevisionsCleanupRoutes constructor.
   */
  public function __construct(WorkspaceManagerInterface $workspaceManager) {
    $this->workspaceManager = $workspaceManager;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('workspaces.manager')
    );
  }

  /**
   * Returns the routes array.
   */
  public function routes() {
    // Add a revisions cleanup route for every content type with workspaces
    // support.
    $routeCollection = new RouteCollection();
    foreach ($this->workspaceManager->getSupportedEntityTypes() as $entityType) {
      $revisionsCleanupRoute = new Route(
        '/' . $entityType->id() . '/{' . $entityType->id() . '}/revisions_cleanup',
        [
          '_form' => '\Drupal\node_revision_delete_workspaces\Controller\RevisionsCleanupConfirmForm',
          '_title' => 'Confirm cleanup',
          'entity_type_id' => $entityType->id(),
        ],
        [
          '_permission' => 'administer node_revision_delete'
        ],
        [
          'parameters' => [
            $entityType->id() => [
              'type' => 'entity:' . $entityType->id(),
            ],
          ],
        ]
      );

      $routeCollection->add('entity.' . $entityType->id() . '.revisions_cleanup', $revisionsCleanupRoute);
    }
    return $routeCollection;
  }
}
