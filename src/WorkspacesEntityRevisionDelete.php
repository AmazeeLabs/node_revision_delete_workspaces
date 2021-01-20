<?php

namespace Drupal\node_revision_delete_workspaces;

use Drupal\node_revision_delete\EntityRevisionDelete;

/**
 * Overrides the original entity revision delete service.
 * Class WorkspacesEntityRevisionDelete
 * @package Drupal\node_revision_delete_workspaces
 */
class WorkspacesEntityRevisionDelete extends EntityRevisionDelete {

  /**
   * {@inheritDoc}
   */
  protected function getCandidatesRevisionsQuery($entity_type, $bundle, array $ids, array $content_type_config, $number = PHP_INT_MAX) {
    $entity_type_definition = $this->entityTypeManager->getDefinition($entity_type);

    $data_table_id = $entity_type_definition->getDataTable();
    $revision_table_id = $entity_type_definition->getRevisionTable();
    $entity_id = $entity_type_definition->getKey('id');
    $revision_id = $entity_type_definition->getKey('revision');
    $revision_date_id = $entity_type_definition->get('revision_metadata_keys');
    $bundle_id = $entity_type_definition->getKey('bundle');
    $revision_parent_field = $entity_type_definition->getRevisionMetadataKey('revision_parent');

    // Hotfix to remove the ONLY_FULL_GROUP_BY flag.
    // See: https://www.drupal.org/project/drupal/issues/2989922#comment-13060134
    $sql_mode = $this->connection->query('SELECT @@sql_mode')->fetchCol();
    if (!empty($sql_mode)) {
      $sql_mode = str_replace('ONLY_FULL_GROUP_BY', '', $sql_mode[0]);
      // After replacing the string, we may have double commas in the new sql
      // mode, so make sure we also remove those.
      $sql_mode = str_replace(',,', ',', $sql_mode);
      // And now just set the new sql mode in the current session.
      $this->connection->query("SET SESSION sql_mode = :sql_mode", [':sql_mode' => $sql_mode]);
    }
    $active_workspace_id = \Drupal::service('workspaces.manager')->getActiveWorkspace()->id();
    $query = $this->connection->select($data_table_id, 'n');
    $query->join($revision_table_id, 'r', "r.{$entity_id} = n.{$entity_id}");
    $query->fields('r', [
      $revision_id,
      $revision_date_id['revision_created'],
    ]);
    $query->condition($revision_date_id['revision_created'], $content_type_config['minimum_age_to_delete'], '<');
    $query->condition("n.{$entity_id}", $ids, 'IN');

    if ($bundle_id) {
      $query->condition($bundle_id, $bundle);
    }

    $query->groupBy("r.{$revision_id}");
    $query->orderBy($revision_date_id['revision_created'], 'DESC');

    // We need to reduce in 1 because we don't want to count the default vid.
    // We excluded the default revision in the where call.
    $offset_subquery = $this->connection->select($revision_table_id, 'more_recent_archived_revisions');
    $offset_subquery->addExpression('COUNT(1)');
    $offset_subquery->where("more_recent_archived_revisions.$entity_id = n.{$entity_id}");
    $offset_subquery->where("more_recent_archived_revisions.$revision_id BETWEEN r.$revision_id AND n.$revision_id");
    // Only consider revisions from the current workspace.
    $offset_subquery->where("more_recent_archived_revisions.workspace=:workspace", [':workspace' => $active_workspace_id]);
    // Make sure to ignore the default revisions.
    $offset_subquery->leftJoin('workspace_association', 'wa', 'wa.target_entity_type_id = :entitytypeid AND wa.target_entity_revision_id = more_recent_archived_revisions.' . $revision_id, ['entitytypeid' => $entity_type]);
    $offset_subquery->condition('wa.target_entity_revision_id', NULL, 'IS NULL');
    // Also, make sure that we ignore the root revision.
    if (!empty($revision_parent_field)) {
      $offset_subquery->condition('more_recent_archived_revisions.' . $revision_parent_field, NULL, 'IS NOT NULL');
    }

    $query->condition($offset_subquery, $content_type_config['minimum_revisions_to_keep'], '>');
    $query->condition($revision_date_id['revision_created'], $content_type_config['minimum_age_to_delete'], '<');
    // Only consider revisions from the current workspace.
    $query->condition('r.workspace', $active_workspace_id);
    // Make sure to ignore the default revisions.
    $query->leftJoin('workspace_association', 'wa', 'wa.target_entity_type_id = :entitytypeid AND wa.target_entity_revision_id = r.' . $revision_id, ['entitytypeid' => $entity_type]);
    $query->condition('wa.target_entity_revision_id', NULL, 'IS NULL');
    // Also, make sure that we ignore the root revision.
    if (!empty($revision_parent_field)) {
      $query->condition('r.' . $revision_parent_field, NULL, 'IS NOT NULL');
    }

    return $query->execute()->fetchCol();
  }

  /**
   * {@inheritdoc}
   */
  public function getCandidatesNodes($entity_type, $bundle) {
    // @TODO check if the method can be improved.
    $result = [];
    // Getting the content type config.
    $content_type_config = $this->getContentTypeConfigWithRelativeTime($entity_type, $bundle);
    $entity_type_definition = $this->entityTypeManager->getDefinition($entity_type);

    $data_table_id = $entity_type_definition->getDataTable();
    $revision_table_id = $entity_type_definition->getRevisionTable();
    $entity_id = $entity_type_definition->getKey('id');
    $bundle_id = $entity_type_definition->getKey('bundle');
    $revision_date_id = $entity_type_definition->get('revision_metadata_keys');
    $active_workspace_id = \Drupal::service('workspaces.manager')->getActiveWorkspace()->id();
    $revision_parent_field = $entity_type_definition->getRevisionMetadataKey('revision_parent');
    if (!empty($content_type_config)) {
      $query = $this->connection->select($data_table_id, 'n');
      $query->join($revision_table_id, 'r', "r.{$entity_id} = n.{$entity_id}");
      $query->fields('n', [$entity_id]);
      $query->addExpression('COUNT(*)', 'total');
      if ($bundle_id) {
        $query->condition($bundle_id, $bundle);
      }
      $query->condition($revision_date_id['revision_created'], $content_type_config['minimum_age_to_delete'], '<');
      $query->groupBy("n.{$entity_id}");
      $query->having('COUNT(*) > ' . $content_type_config['minimum_revisions_to_keep']);

      // Only consider revisions from the current workspace.
      $query->condition('r.workspace', $active_workspace_id);
      // Also, make sure that we ignore the root revision.
      if (!empty($revision_parent_field)) {
        $query->condition('r.' . $revision_parent_field, NULL, 'IS NOT NULL');
      }

      // Allow other modules to alter candidates query.
      $query->addTag('node_revision_delete_candidates');
      $query->addTag('node_revision_delete_candidates_' . $entity_type . '_' . $bundle);

      $result = $query->execute()->fetchCol();
    }

    return $result;
  }

}
