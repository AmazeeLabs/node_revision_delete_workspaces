<?php

namespace Drupal\node_revision_delete_workspaces\Commands;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node_revision_delete\EntityRevisionDeleteInterface;
use Drupal\node_revision_delete_workspaces\WorkspacesEntityRevisionDeleteBatch;
use Drupal\workspaces\WorkspaceManagerInterface;
use Drush\Commands\DrushCommands;

class RevisionCleanupCommands extends DrushCommands {
  use StringTranslationTrait;

  /**
   * The entity type manager service.
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The workspace manager service.
   * @var \Drupal\workspaces\WorkspaceManagerInterface
   */
  protected $workspaceManger;

  /**
   * The entity revision delete service.
   * @var EntityRevisionDeleteInterface
   */
  protected $entityRevisionDelete;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, WorkspaceManagerInterface $workspace_manager, EntityRevisionDeleteInterface $entity_revision_delete) {
    $this->entityTypeManager = $entity_type_manager;
    $this->workspaceManger = $workspace_manager;
    $this->entityRevisionDelete = $entity_revision_delete;
  }

  /**
   * Cleanup revisions
   *
   * @option workspaces A comma separated list of workspaces.
   * @option entity_types A comma separated list of entity types.
   *
   * @command entity-revision-cleanup-workspaces
   */
  public function revisionsCleanup($options = ['workspaces' => '', 'entity_types' => '']) {
    if (!empty($options['workspaces'])) {
      $workspace_ids = explode(',', $options['workspaces']);
    } else {
      $workspace_ids = array_map(function($workspace) {
        return $workspace->id();
      }, $this->entityTypeManager->getStorage('workspace')->loadMultiple());
    }
    $configured_entity_types = $this->entityRevisionDelete->getConfiguredContentTypes();
    if (!empty($options['entity_types'])) {
      $entity_types = explode(',', $options['entity_types']);
    } else {
      $entity_types = array_keys($configured_entity_types);
    }
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Revisions cleanup'))
      ->setFinishCallback([WorkspacesEntityRevisionDeleteBatch::class, 'finish'])
      ->setInitMessage($this->t('Starting to cleanup revisions'));

    foreach ($workspace_ids as $workspace_id) {
      foreach ($entity_types as $entity_type) {
        if (!isset($configured_entity_types[$entity_type])) {
          continue;
        }
        foreach ($configured_entity_types[$entity_type] as $bundle) {
          $candidates = $this->entityRevisionDelete->getCandidatesNodes($entity_type, $bundle);
          foreach ($candidates as $entity_id) {
            $batch->addOperation(
              [WorkspacesEntityRevisionDeleteBatch::class, 'executeForWorkspace'],
              [$workspace_id, $entity_type, $bundle, $entity_id]
            );
          }
        }
      }
    }
    batch_set($batch->toArray());
    drush_backend_batch_process();
  }
}
