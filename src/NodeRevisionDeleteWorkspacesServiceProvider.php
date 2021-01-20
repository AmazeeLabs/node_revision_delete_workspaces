<?php

namespace Drupal\node_revision_delete_workspaces;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Class NodeRevisionDeleteWorkspacesServiceProvider
 * @package Drupal\node_revision_delete_workspaces
 */
class NodeRevisionDeleteWorkspacesServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritDoc}
   */
  public function alter(ContainerBuilder $container) {
    $definition = $container->getDefinition('entity_revision_delete');
    if (!empty($definition)) {
      $definition->setClass(WorkspacesEntityRevisionDelete::class);
    }
  }
}
