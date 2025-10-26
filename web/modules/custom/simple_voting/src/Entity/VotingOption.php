<?php

namespace Drupal\simple_voting\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityOwnerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\simple_voting\Traits\EntityChangedTrait;

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
 *     "label" = "title"
 *   },
 *   handlers = {
 *     "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "form" = {
 *       "default" = "Drupal\Core\Entity\ContentEntityForm",
 *       "add" = "Drupal\Core\Entity\ContentEntityForm",
 *       "edit" = "Drupal\Core\Entity\ContentEntityForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *       }
 *   }
 * )
 */
final class VotingOption extends ContentEntityBase implements EntityChangedInterface {
  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Short label.
    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Title'))
      ->setRequired(TRUE)
      ->setSettings(['max_length' => 255])
      ->setDescription(new TranslatableMarkup('The short label for this voting option.'));

    // Optional description text for the option.
    $fields['text'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Option Text'))
      ->setRequired(FALSE)
      ->setDescription(new TranslatableMarkup('A detailed description of the option.'));
    
    // Optional image
    $fields['image'] = BaseFieldDefinition::create('image')
      ->setLabel(new TranslatableMarkup('Option Image'))
      ->setRequired(FALSE)
      ->setDescription(new TranslatableMarkup('An optional image associated with this option.'));

    // Reference to the parent VotingQuestion entity.
    $fields['question_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Voting Question'))
      ->setSetting('target_type', 'voting_question')
      ->setRequired(TRUE)
      ->setDescription(new TranslatableMarkup('The parent question this option belongs to.'));

    // Timestamp of the last update.
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Last Update'));

    return $fields;
  }

}
