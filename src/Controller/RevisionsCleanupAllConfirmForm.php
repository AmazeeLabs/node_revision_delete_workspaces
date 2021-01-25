<?php

namespace Drupal\node_revision_delete_workspaces\Controller;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node_revision_delete\EntityRevisionDeleteInterface;
use Drupal\node_revision_delete_workspaces\WorkspacesEntityRevisionDeleteBatch;
use Drupal\workspaces\WorkspaceManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class RevisionsCleanupAllConfirmForm extends ConfirmFormBase {

  /**
   * @var WorkspaceManagerInterface
   */
  protected $workspaceManager;

  /**
   * @var EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var EntityRevisionDeleteInterface
   */
  protected $entityRevisionDelete;

  /**
   * RevisionsCleanupConfirmForm constructor.
   */
  public function __construct(WorkspaceManagerInterface $workspace_manager, EntityTypeManagerInterface $entity_type_manager, EntityRevisionDeleteInterface $entity_revision_delete) {
    $this->workspaceManager = $workspace_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityRevisionDelete = $entity_revision_delete;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container){
    return new static(
      $container->get('workspaces.manager'),
      $container->get('entity_type.manager'),
      $container->get('entity_revision_delete')
    );
  }

  /**
   * {@inheritDoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to cleanup all the revisions from all the workspaces?');
  }

  /**
   * {@inheritDoc}
   */
  public function getCancelUrl() {
    return new Url('<front>');
  }

  /**
   * {@inheritDoc}
   */
  public function getFormId() {
    return 'revisions_cleanup_confirm_form_all';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $entity_type_id = NULL) {
    $workspaces = $this->entityTypeManager->getStorage('workspace')->loadMultiple();
    $options = [
      '--all_workspaces--' => $this->t('All workspaces'),
    ];
    foreach ($workspaces as $workspace) {
      $options[$workspace->id()] = $workspace->label();
    }
    $form['workspaces'] = [
      '#title' => $this->t('Workspace'),
      '#type' => 'checkboxes',
      '#options' => $options,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritDoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $selected_workspace_ids = array_filter($form_state->getValue('workspaces'));
    $workspace_ids = in_array('--all_workspaces--', $selected_workspace_ids) ? NULL : $selected_workspace_ids;
    $workspaces = $this->entityTypeManager->getStorage('workspace')->loadMultiple($workspace_ids);
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Revisions cleanup'))
      ->setFinishCallback([WorkspacesEntityRevisionDeleteBatch::class, 'finish'])
      ->setInitMessage($this->t('Starting to cleanup revisions'));
    foreach ($workspaces as $workspace) {
      $this->workspaceManager->executeInWorkspace($workspace->id(), function() use ($batch, $workspace) {
        $content_types = $this->entityRevisionDelete->getConfiguredContentTypes();
        foreach ($content_types as $content_type => $bundles) {
          foreach ($bundles as $bundle) {
            $candidates = $this->entityRevisionDelete->getCandidatesNodes($content_type, $bundle);
            foreach ($candidates as $entity_id) {
              $batch->addOperation(
                [WorkspacesEntityRevisionDeleteBatch::class, 'executeForWorkspace'],
                [$workspace->id(), $content_type, $bundle, $entity_id]
              );
            }
          }
        }
      });
    }
    batch_set($batch->toArray());
  }
}
