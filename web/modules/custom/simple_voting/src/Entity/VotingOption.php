<?php

namespace Drupal\simple_voting\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityOwnerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Defines the VotingOption entity.
 *
 * @ContentEntityType(
 *   id = "voting_option",
 *   label = @Translation("Voting Option"),
 *   base_table = "voting_option",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "label"
 *   },
 *   handlers = {
 *     "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "form" = {
 *       "default" = "Drupal\Core\Entity\ContentEntityForm",
 *       "add" = "Drupal\Core\Entity\ContentEntityForm",
 *       "edit" = "Drupal\Core\Entity\ContentEntityForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityForm"
 *       }
 *   }
 * )
 */
final class VotingOption extends ContentEntityBase implements EntityChangedInterface {
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = [];

    // Short label.
    $fields['label'] = baseFieldDefinition::create('string')
      ->setLabel(static::t('Label'))
      ->setRequired(TRUE)
      ->setSettings(['max_length' => 255]);

    // Optional description text for the option.
    $fields['text'] = BaseFieldDefinition::create('text_long')
      ->setLabel(static::t('Option Text'))
      ->setRequired(FALSE);
    
    // Optional image
    $fields['image'] = baseFieldDefinition::create('image')
      ->setLabel(static::t('Option Image'))
      ->setRequired(FALSE);

    // Reference to the parent VotingQuestion entity.
    $fields['question_id'] = baseFieldDefinition::create('entity_reference')
      ->setLabel(static::t('Voting Question'))
      ->setSetting('target_type', 'voting_question')
      ->setRequired(TRUE);

    // Timestamp of the last update.
    $fields['changed'] = baseFieldDefinition::create('changed')
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

