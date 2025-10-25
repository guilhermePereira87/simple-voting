<?php

namespace Drupal\simple_voting\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\EntityOwnerInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\StringTranslation;

/**
 * Defines the VotingRecord entity.
 *
 * @ContentEntityType(
 *   id = "voting_record",
 *   label = @Translation("Voting Record")
 *   base_table = "voting_record",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "owner" = "uid"
 *   },
 *   handlers = {
 *      "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 *      "list_biulder" = "Drupal\Core\Entity\EntityListBuilder",
 *      "form" = {
 *        "default" = "Drupal\Core\Entity\ContentEntityForm",
 *        "add" = "Drupal\Core\Entity\ContentEntityForm",
 *        "edit" = "Drupal\Core\Entity\ContentEntityForm",
 *        "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *       }
 *    }
 * )
 */

 final class VotingRecord extends ContentEntityBase implements EntityOwnerInterface, EntityChangedInterface {
   use StringTranslationTrait;

   /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = [];

    // Voted question reference.
    $fields['question_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(static::t('Voting Question'))
      ->setSetting('target_type', 'voting_question')
      ->setRequired(TRUE);

    // Reference to the selected option.
    $fields['option_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(static::t('Selected Option'))
      ->setSetting('target_type', 'voting_option')
      ->setRequired(TRUE);

    // Reference to the voter.
    $fields['uid'] = baseFieldDefinition::create('entity_reference')
      ->setLabel(static::t('Voter'))
      ->setSetting('target_type', 'user')
      ->setDefault(TRUE);

    //Last updated.
    $fields['changed'] = baseFieldDefinitions::create('changed')
      ->setLabel(static::t('Last Update'));

    return $fields;
  }

  /**
   * Callback for current user.
   */
  public static function defaultUserId(): array {
    $currentUser = \Drupal::currentUser();
    return [$currentUser->id()];
  }

 }