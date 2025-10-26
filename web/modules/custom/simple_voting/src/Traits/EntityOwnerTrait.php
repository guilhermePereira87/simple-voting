<?php

namespace Drupal\simple_voting\Traits;

use Drupal\user\UserInterface;

/**
 * Provides shared implementation for EntityOwnerInterface methods.
 */
trait EntityOwnerTrait {
    /**
   * Gets the owner ID.
   *
   * @return \Drupal\user\UserInterface|null
   *   The owner user entity, if exists.
   */
  public function getOwner(): UserInterface {
    return $this->get('uid')->entity;
  }

  /**
   * Sets the owner user entity.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user account to be set as owner.
   *
   * @return $this
   *   The called entity.
   */
  public function setOwner(UserInterface $account) {
    $this->set('uid', $account->id());
    return $this;
  }

  /**
   * Gets the owner ID.
   *
   * @return int|null
   *  
   */
  public function getOwnerId() {
    return $this->get('uid')->target_id;
  }

  /**
   * Sets the owner ID.
   *
   * @param int $uid
   *   The user ID to set as owner.
   *
   * @return $this
   *   The called entity.
   */
  public function setOwnerId($uid) {
    $this->set('uid', $uid);
    return $this;
  }
}