<?php

namespace Drupal\simple_voting\Access;

use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Access control handler for VotingQuestion entities.
 */
class VotingQuestionAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {

    // Admins can perform any operation.
    if ($account->hasPermission('administer voting questions')) {
      return AccessResult::allowed();
    }

    switch ($operation) {
      case 'view':
        // Can be visualized if active.
        return AccessResult::allowedIf($entity->isPublished());

      case 'update':
      case 'delete':
        // Only the question owner can edit/delete it.
        if ($entity->getOwnerId() === $account->id()) {
          return AccessResult::allowed();
        }
        return AccessResult::forbidden();
    }

    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    // Users with permission create new questions.
    return AccessResult::allowedIfHasPermission($account, 'create voting questions');
  }

}
