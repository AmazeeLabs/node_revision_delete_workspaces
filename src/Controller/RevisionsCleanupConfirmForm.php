<?php

namespace Drupal\node_revision_delete_workspaces\Controller;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\workspaces\WorkspaceManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the revisions cleanup of an entity.
 *
 * Class RevisionsCleanup
 * @package Drupal\node_revision_delete_workspaces\Controller
 */
class RevisionsCleanupConfirmForm extends ConfirmFormBase {

  /**
   * @var ContentEntityInterface
   */
  protected $entity;

  /**
   * @var WorkspaceManagerInterface
   */
  protected $workspaceManager;

  /**
   * @var EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * RevisionsCleanupConfirmForm constructor.
   */
  public function __construct(WorkspaceManagerInterface $workspace_manager, EntityTypeManagerInterface $entity_type_manager) {
    $this->workspaceManager = $workspace_manager;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container){
    return new static(
      $container->get('workspaces.manager'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritDoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $entity_type_id = NULL) {
    $this->entity = $this->getRouteMatch()->getParameter($entity_type_id);
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritDoc}
   */
  public function getQuestion() {
    return $this->t('Do you want to cleanup the revisions for this content?');
  }

  /**
   * {@inheritDoc}
   */
  public function getCancelUrl() {
    return new Url('entity.' . $this->entity->getEntityTypeId() . '.canonical', [$this->entity->getEntityTypeId() => $this->entity->id()]);
  }

  /**
   * {@inheritDoc}
   */
  public function getFormId() {
    return 'revisions_cleanup_confirm_form';
  }

  /**
   * {@inheritDoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $workspaces = $this->entityTypeManager->getStorage('workspace')->loadMultiple();
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Revisions cleanup'))
      ->setFinishCallback([RevisionsCleanupConfirmForm::class, 'finish'])
      ->setInitMessage($this->t('Starting to cleanup revisions'));
    foreach ($workspaces as $workspace) {
      $batch->addOperation(
        [RevisionsCleanupConfirmForm::class, 'executeForWorkspace'],
        [$workspace, $this->entity]
      );
    }
    batch_set($batch->toArray());
  }

  public static function executeForWorkspace($workspace, ContentEntityInterface $entity, &$context) {
    try {
      /* @var WorkspaceManagerInterface $workspacesManager */
      $workspacesManager = \Drupal::getContainer()->get('workspaces.manager');
      $active = $workspacesManager->getActiveWorkspace()->id();
      $workspacesManager->executeInWorkspace($workspace->id(), function() use ($active, $context, $workspace, $workspacesManager, $entity) {
        if (empty($context['sandbox'])) {
          $candidate_revisions = \Drupal::getContainer()->get('entity_revision_delete')->getCandidatesRevisionsByIds($entity->getEntityTypeId(), $entity->bundle(), [$entity->id()]);
          $context['sandbox']['progress'] = 0;
          $context['sandbox']['candidate_revisions'] = $candidate_revisions;
          $context['sandbox']['max'] = count($candidate_revisions);
        }
        $index = $context['sandbox']['progress'];
        if (!empty($context['sandbox']['candidate_revisions'][$index])) {
          $revision_id = $context['sandbox']['candidate_revisions'][$index];
          \Drupal::entityTypeManager()->getStorage($entity->getEntityTypeId())->deleteRevision($revision_id);
        }
        $context['sandbox']['progress']++;
        $context['message'] = \Drupal::translation()->translate('Cleaning up for workspace: @workspace', ['@workspace' => $workspace->id()]);
      });
    } catch (\Exception $e) {
      // @todo: log maybe the exception?
    }
    if ($context['sandbox']['progress'] < count($context['sandbox']['candidate_revisions']) -1 ) {
      $context['finished'] = $context['sandbox']['progress'] / (count($context['sandbox']['candidate_revisions']) -1);
    } else {
      $context['finished'] = 1;
    }
  }

  /**
   * Finish callback for the batch operation.
   */
  public static function finish($success, array $results, array $operations) {
    $messenger = \Drupal::messenger();
    if ($success) {
      $messenger->addMessage(t('The operation succeeded.'));
    }
    else {
      // An error occurred.
      // $operations contains the operations that remained unprocessed.
      $error_operation = reset($operations);
      $message = t('An error occurred while processing %error_operation with arguments: @arguments', [
        '%error_operation' => $error_operation[0],
        '@arguments' => print_r($error_operation[1], TRUE),
      ]);
      $messenger->addError($message);
    }
  }
}
