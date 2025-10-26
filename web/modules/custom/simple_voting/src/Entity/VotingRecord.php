<?php

namespace Drupal\simple_voting\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\EntityOwnerInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\simple_voting\Traits\EntityChangedTrait;

/**
 * Defines the VotingRecord entity.
 *
 * @ContentEntityType(
 *   id = "voting_record",
 *   label = @Translation("Voting Record"),
 *   base_table = "voting_record",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "owner" = "uid"
 *   },
 *   handlers = {
 *      "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 *      "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *      "form" = {
 *        "default" = "Drupal\Core\Entity\ContentEntityForm",
 *        "add" = "Drupal\Core\Entity\ContentEntityForm",
 *        "edit" = "Drupal\Core\Entity\ContentEntityForm",
 *        "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *       }
 *    },
 *    constraints = {
 *      "UniqueField" = {
 *        "fields" = {"question_id", "uid"},
 *        "message" = @Translation("Each user can only vote once per question.")
 *      }
 *    }
 * )
 */

 final class VotingRecord extends ContentEntityBase implements EntityOwnerInterface, EntityChangedInterface {
   use StringTranslationTrait;
   use EntityChangedTrait;

   /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Voted question reference.
    $fields['question_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Voting Question'))
      ->setSetting('target_type', 'voting_question')
      ->setRequired(TRUE)
      ->setDescription(t('Reference to the question this vote belongs to.'));

    // Reference to the selected option.
    $fields['option_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Selected Option'))
      ->setSetting('target_type', 'voting_option')
      ->setRequired(TRUE)
      ->setDescription(t('Reference to the selected option.'));

    // Reference to the voter.
    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(static::t('Voter'))
      ->setSetting('target_type', 'user')
      ->setDescription(t('The user who voted.'));

    //Last updated.
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Last Update'));

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