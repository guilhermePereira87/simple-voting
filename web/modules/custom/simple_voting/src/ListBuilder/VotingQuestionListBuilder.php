<?php

namespace Drupal\simple_voting\ListBuilder;

use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Provides a list builder for VotingQuestion entities on the admin collection page.
 */
class VotingQuestionListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['title'] = $this->t('Title');
    $header['author'] = $this->t('Author');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /* @var \Drupal\simple_voting\Entity\VotingQuestion $entity */
    $row['title'] = $entity->label();
    $ownerEntity = NULL;
    if ($entity->hasField('uid')) {
      $ownerEntity = $entity->get('uid')->entity;
    }
    $row['author'] = $ownerEntity ? $ownerEntity->getAccountName() : '';

    // Let the parent add default operations column (Edit/Delete) and other
    // default cells (if any).
    return $row + parent::buildRow($entity);
  }

}
