<?php

namespace Drupal\node_revision_delete_workspaces;

/**
 *
 */
class WorkspacesEntityRevisionDeleteBatch {

  /**
   *
   */
  public static function executeForWorkspace($workspace_id, $entity_type_id, $entity_bundle, $entity_id, $dry_run, &$context) {
    try {
      /** @var WorkspaceManagerInterface $workspacesManager */
      $workspacesManager = \Drupal::getContainer()->get('workspaces.manager');
      $workspacesManager->executeInWorkspace($workspace_id, function () use ($context, $workspace_id, $entity_type_id, $entity_bundle, $entity_id, $dry_run) {
        if (empty($context['sandbox'])) {
          $candidate_revisions = \Drupal::getContainer()->get('entity_revision_delete')->getCandidatesRevisionsByIds($entity_type_id, $entity_bundle, [$entity_id]);
          $context['sandbox']['progress'] = 0;
          $context['sandbox']['candidate_revisions'] = $candidate_revisions;
          $context['sandbox']['max'] = count($candidate_revisions);
        }
        $index = $context['sandbox']['progress'];
        if (!empty($context['sandbox']['candidate_revisions'][$index])) {
          $revision_id = $context['sandbox']['candidate_revisions'][$index];
          if (!$dry_run) {
            \Drupal::entityTypeManager()->getStorage($entity_type_id)->deleteRevision($revision_id);
          }
          $context['results']['removed_revisions'][$workspace_id][] = $revision_id;
        }
        $context['sandbox']['progress']++;
        $context['message'] = \Drupal::translation()->translate('Cleaning up for workspace: @workspace and @entity_type_id: @entity_id', [
          '@workspace' => $workspace_id,
          '@entity_type_id' => $entity_type_id,
          '@entity_id' => $entity_id,
        ]
        );
      });
    }
    catch (\Exception $e) {
      // @todo log maybe the exception?
    }
    if (
      isset($context['sandbox']['progress']) &&
      isset($context['sandbox']['candidate_revisions']) &&
      is_countable($context['sandbox']['candidate_revisions']) &&
      $context['sandbox']['progress'] < count($context['sandbox']['candidate_revisions'])) {
      $context['finished'] = $context['sandbox']['progress'] / (count($context['sandbox']['candidate_revisions']));
    }
    else {
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
      if (isset($results['removed_revisions'])) {
        foreach ($results['removed_revisions'] as $workspace_id => $revisions) {
          $messenger->addMessage(t('@count revisions have been removed for workspace @workspace', ['@workspace' => $workspace_id, '@count' => count($revisions)]));
        }
      }
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
