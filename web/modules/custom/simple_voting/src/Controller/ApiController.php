<?php

namespace Drupal\simple_voting\Controller;

use Drupal\user\Entity\User;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\user\UserInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\user\UserAuthInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Simple API controller for the simple_voting module.
 */
class ApiController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * File URL generator service.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Entity query factory.
   *
   * @var \Drupal\Core\Entity\Query\QueryFactory
   */
  protected $entityQuery;

  /**
   * User auth service.
   *
   * @var \Drupal\user\UserAuthInterface
   */
  protected $userAuth;

  /**
   * Logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * ApiController constructor.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, FileUrlGeneratorInterface $file_url_generator, AccountProxyInterface $current_user, QueryFactory $entity_query, UserAuthInterface $user_auth, LoggerChannelFactoryInterface $logger_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fileUrlGenerator = $file_url_generator;
    $this->currentUser = $current_user;
    $this->entityQuery = $entity_query;
    $this->userAuth = $user_auth;
    $this->logger = $logger_factory->get('simple_voting');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('file_url_generator'),
      $container->get('current_user'),
      $container->get('entity.query'),
      $container->get('user.auth'),
      $container->get('logger.factory')
    );
  }

  /**
   * Return a JSON list of published voting questions and their options.
   *
   * GET /api/v1/questions.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response containing the list of questions and options.
   */
  public function questions() {
    $questionStorage = $this->entityTypeManager->getStorage('voting_question');
    $optionStorage = $this->entityTypeManager->getStorage('voting_option');
    $fileStorage = $this->entityTypeManager->getStorage('file');

    // Load published questions.
    $ids = $questionStorage->getQuery()
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->sort('id', 'DESC')
      ->execute();

    $questions = [];
    if (!empty($ids)) {
      $entities = $questionStorage->loadMultiple($ids);
      foreach ($entities as $q) {
        /** @var \Drupal\Core\Entity\FieldableEntityInterface $q */
        $qid = $q->id();
        $qItem = [
          'id' => $qid,
          'title' => $q->label(),
          'question_text' => ($q->hasField('question_text') && !$q->get('question_text')->isEmpty()) ? $q->get('question_text')->value : '',
          'show_results' => ($q->hasField('show_results') && !$q->get('show_results')->isEmpty()) ? (bool) $q->get('show_results')->value : FALSE,
        ];

        // Return only basic question data (id, title, question_text).
        // Options are intentionally omitted to keep the response small.
        $qItem['question_text'] = ($q->hasField('question_text') && !$q->get('question_text')->isEmpty())
          ? $q->get('question_text')->value : '';
        $questions[] = [
          'id' => $qItem['id'],
          'title' => $qItem['title'],
          'question_text' => $qItem['question_text'],
        ];
      }
    }

    $response = [
      'data' => $questions,
      'meta' => ['count' => count($questions)],
    ];
    return new JsonResponse($response);
  }

  /**
   * Return the question JSON especified by ID.
   *
   * GET /api/v1/questions/{id}.
   *
   * @param int $id
   *   The question ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response containing the question and its options.
   */
  public function getQuestionById($id) {
    $questionStorage = $this->entityTypeManager->getStorage('voting_question');
    $question = $questionStorage->load($id);
    if (!$question) {
      return new JsonResponse(['error' => 'Question not found'], 404);
    }

    $data = [
      'id' => $question->id(),
      'title' => $question->label(),
      'question_text' => ($question->hasField('question_text') && !$question->get('question_text')->isEmpty()) ? $question->get('question_text')->value : '',
    ];

    // Load options for this question and include them in the response.
    $optionStorage = $this->entityTypeManager->getStorage('voting_option');
    $fileStorage = $this->entityTypeManager->getStorage('file');
    $optIds = $optionStorage->getQuery()
      ->condition('question_id', $question->id())
      ->accessCheck(FALSE)
      ->sort('id', 'ASC')
      ->execute();

    $options = [];
    if (!empty($optIds)) {
      $optEntities = $optionStorage->loadMultiple($optIds);
      foreach ($optEntities as $optEntity) {
        /** @var \Drupal\Core\Entity\FieldableEntityInterface $opt */
        $imageUrl = NULL;
        if ($optEntity->hasField('image') && !$optEntity->get('image')->isEmpty()) {
          $fid = $optEntity->get('image')->target_id;
          if ($fid) {
            $file = $fileStorage->load($fid);
            if ($file) {
              $uri = $file->getFileUri();
              $imageUrl = $this->fileUrlGenerator->generateAbsoluteString($uri);
            }
          }
        }

        $options[] = [
          'id' => $optEntity->id(),
          'title' => $optEntity->label(),
          'description' => ($optEntity->hasField('text') && !$optEntity->get('text')->isEmpty()) ? $optEntity->get('text')->value : NULL,
          'image_url' => $imageUrl,
        ];
      }
    }

    $data['options'] = $options;

    return new JsonResponse(['data' => $data]);
  }

  /**
   * Return voting results for a question if allowed.
   *
   * GET /api/v1/questions/{id}/results.
   *
   * @param int $id
   *   The question ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response containing the voting results.
   */
  public function getQuestionResults($id) {
    $questionStorage = $this->entityTypeManager->getStorage('voting_question');
    $question = $questionStorage->load($id);
    if (!$question) {
      return new JsonResponse(['error' => 'Question not found'], 404);
    }

    // Determine whether results are visible for this question.
    $showResults = FALSE;
    if ($question->hasField('show_results') && !$question->get('show_results')->isEmpty()) {
      $showResults = (bool) $question->get('show_results')->value;
    }

    $currentUser = $this->currentUser;
    // Allow access only when show_results is true or the current user has the
    // administrative permission required to view results.
    if (!$showResults || !$currentUser->hasPermission('administer site configuration')) {
      return new JsonResponse(['error' => 'Results are not available for this question'], 403);
    }

    // Load options and compute counts.
    $optionStorage = $this->entityTypeManager->getStorage('voting_option');
    $optIds = $optionStorage->getQuery()
      ->condition('question_id', $question->id())
      ->accessCheck(FALSE)
      ->sort('id', 'ASC')
      ->execute();

    $options = [];
    $totalVotes = 0;
    $counts = [];
    if (!empty($optIds)) {
      // Use entityQuery to count voting_record per option.
      foreach ($optIds as $optId) {
        $query = $this->entityQuery->get('voting_record')
          ->accessCheck(FALSE)
          ->condition('question_id', $question->id())
          ->condition('option_id', $optId)
          ->count();
        $count = (int) $query->execute();
        $counts[$optId] = $count;
        $totalVotes += $count;
      }

      $optEntities = $optionStorage->loadMultiple($optIds);
      foreach ($optEntities as $optEntity) {
        $optId = $optEntity->id();
        $options[] = [
          'id' => $optId,
          'title' => $optEntity->label(),
          'count' => $counts[$optId] ?? 0,
        ];
      }
    }

    $data = [
      'id' => $question->id(),
      'title' => $question->label(),
      'total_votes' => $totalVotes,
      'options' => $options,
    ];

    return new JsonResponse(['data' => $data]);
  }

  /**
   * Vote in a question when applicable.
   *
   * POST /api/v1/vote/{questionId}/{optionId}.
   *
   * @param int $questionId
   *   The question ID.
   * @param int $optionId
   *   The option ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response indicating success or failure.
   */
  public function vote($questionId, $optionId) {
    try {
      $currentUser = $this->currentUser;
      // Require authenticated user for API voting. Anonymous votes are not allowed
      // via the API to ensure each vote is attributable to a user.
      if (!$currentUser->isAuthenticated()) {
        return new JsonResponse(['error' => 'Authentication required'], 401);
      }

      $questionStorage = $this->entityTypeManager->getStorage('voting_question');
      $optionStorage = $this->entityTypeManager->getStorage('voting_option');
      $recordStorage = $this->entityTypeManager->getStorage('voting_record');

      // Validate question and option.
      $question = $questionStorage->load($questionId);
      if (!$question) {
        return new JsonResponse(['error' => 'Question not found'], 404);
      }
      // Option must exist and belong to the question.
      $option = $optionStorage->load($optionId);
      if (!$option) {
        return new JsonResponse(['error' => 'Option not found'], 404);
      }
      // Some implementations store question_id as an entity reference field.
      if (method_exists($option, 'hasField') && $option->hasField('question_id')) {
        $optQ = NULL;
        if (method_exists($option, 'get')) {
          $optField = $option->get('question_id');
          if (is_object($optField)) {
            $optQ = $optField->target_id ?? $optField->value ?? NULL;
          }
        }
        if ((string) $optQ !== (string) $questionId) {
          return new JsonResponse(['error' => 'Option does not belong to the question'], 400);
        }
      }

      // Check if the current user (authenticated) already voted.
      if ($currentUser->isAuthenticated()) {
        $uid = $currentUser->id();
        $existing = $recordStorage->loadByProperties([
          'question_id' => $questionId,
          'uid' => $uid,
        ]);
        if (!empty($existing)) {
          return new JsonResponse(['error' => 'User already voted'], 409);
        }

        // Create the voting_record for authenticated user.
        $record = $recordStorage->create([
          'question_id' => $questionId,
          'option_id' => $optionId,
          'uid' => $uid,
        ]);
        $record->save();

        return new JsonResponse(['status' => 'ok', 'vote_id' => $record->id()], 201);
      }

      // At this point the user is authenticated. Enforce single vote per user
      // and create the voting record with the user's uid.
      $uid = $currentUser->id();
      $existing = $recordStorage->loadByProperties([
        'question_id' => $questionId,
        'uid' => $uid,
      ]);
      if (!empty($existing)) {
        return new JsonResponse(['error' => 'User already voted'], 409);
      }

      $record = $recordStorage->create([
        'question_id' => $questionId,
        'option_id' => $optionId,
        'uid' => $uid,
      ]);
      $record->save();

      return new JsonResponse(['status' => 'ok', 'vote_id' => $record->id()], 201);
    }
    catch (\Exception $e) {
      $message = $e->getMessage();
      $code = $e->getCode();
      $isDuplicate = strpos($message, 'Duplicate') !== FALSE
        || (is_string($code) && strpos($code, '23000') !== FALSE)
        || $code === 23000;
      if ($isDuplicate) {
        return new JsonResponse(['error' => 'Already voted'], 409);
      }
      $this->logger->error('Unexpected error in API vote: @msg', ['@msg' => $message]);
      return new JsonResponse(['error' => 'Internal server error'], 500);
    }
  }

  /**
   * Authenticate a user and start a session.
   *
   * POST /api/v1/login.
   * Expects JSON body: { "name": "username", "pass": "password" }
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function login(Request $request) {
    // Accept JSON or form-encoded bodies.
    $data = [];
    $content = $request->getContent();
    if (!empty($content)) {
      $decoded = json_decode($content, TRUE);
      if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $data = $decoded;
      }
    }
    // Fallback to request parameters if not JSON.
    if (empty($data)) {
      $data['name'] = $request->request->get('name');
      $data['pass'] = $request->request->get('pass');
    }

    $name = $data['name'] ?? NULL;
    $pass = $data['pass'] ?? NULL;
    if (empty($name) || empty($pass)) {
      return new JsonResponse(['error' => 'Missing credentials'], 400);
    }

    try {
      $auth = $this->userAuth;
      $uid = $auth->authenticate($name, $pass);
      if ($uid === FALSE || $uid === 0) {
        return new JsonResponse(['error' => 'Invalid credentials'], 401);
      }

      // Load user entity and finalize login (sets session, cookies, etc.).
      $account = User::load($uid);
      if (!$account instanceof UserInterface) {
        return new JsonResponse(['error' => 'Invalid user account'], 401);
      }

      // Finalize login: sets current user in session and sends cookie in response.
      user_login_finalize($account);

      return new JsonResponse(['status' => 'ok', 'uid' => $uid]);
    }
    catch (\Exception $e) {
      $this->logger->error('Login error: @msg', ['@msg' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Internal server error'], 500);
    }
  }

}
