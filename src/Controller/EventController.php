<?php

/**
 * @file
 * Contains \Drupal\rng\Controller\EventController.
 */

namespace Drupal\rng\Controller;

use Drupal\Core\Action\ActionManager;
use Drupal\Core\Condition\ConditionManager;
use Drupal\rng\EventManagerInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\rng\RNGConditionInterface;

/**
 * Controller for events.
 */
class EventController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The action manager service.
   *
   * @var \Drupal\Core\Action\ActionManager
   */
  protected $actionManager;

  /**
   * The condition manager service.
   *
   * @var \Drupal\Core\Condition\ConditionManager
   */
  protected $conditionManager;

  /**
   * The RNG event manager.
   *
   * @var \Drupal\rng\EventManagerInterface
   */
  protected $eventManager;

  /**
   * Constructs a new action form.
   *
   * @param \Drupal\Core\Action\ActionManager $actionManager
   *   The action manager.
   * @param \Drupal\Core\Condition\ConditionManager $conditionManager
   *   The condition manager.
   * @param \Drupal\rng\EventManagerInterface $event_manager
   *   The RNG event manager.
   */
  public function __construct(ActionManager $actionManager, ConditionManager $conditionManager, EventManagerInterface $event_manager) {
    $this->actionManager = $actionManager;
    $this->conditionManager = $conditionManager;
    $this->eventManager = $event_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.action'),
      $container->get('plugin.manager.condition'),
      $container->get('rng.event_manager')
    );
  }

  /**
   * Displays a list of actions which are related to registration access on an
   * event.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route.
   * @param string $event
   *   The parameter to find the event entity.
   *
   * @return array
   *   A render array.
   */
  public function listing_access(RouteMatchInterface $route_match, $event) {
    $event = $route_match->getParameter($event);
    $destination = drupal_get_destination();
    $build = [];

    $build['description'] = [
      '#prefix' => '<p>',
      '#markup' => $this->t('The following rules determine who is eligible to register or perform an operation on a registration.<br />Access is granted if all conditions for a rule evaluate as true.'),
      '#suffix' => '</p>',
    ];

    $rows = [];

    // Header
    $rows[] = [
      ['header' => TRUE, 'rowspan' => 2, 'data' => $this->t('Rule')],
      ['header' => TRUE, 'rowspan' => 2, 'data' => $this->t('Component')],
      ['header' => TRUE, 'rowspan' => 2, 'data' => $this->t('Scope')],
      ['header' => TRUE, 'rowspan' => 1, 'data' => $this->t('Operations'), 'colspan' => 4],
      ['header' => TRUE, 'rowspan' => 2, 'data' => ''],
    ];
    $operations = ['create' => $this->t('Create'), 'view' => $this->t('View'), 'update' => $this->t('Update'), 'delete' => $this->t('Delete')];
    foreach ($operations as $operation) {
      $rows['operations'][] = [
        'header' => TRUE,
        'data' => $operation,
      ];
    }

    $i = 0;
    $rules = $this->eventManager->getMeta($event)->getRules('rng_event.register');
    foreach($rules as $rule) {
      $i++;
      $scope_all = FALSE;
      $supports_create = 0;
      $condition_context = [];

      // Conditions
      $k = 0;
      $row = [];
      $row['rule'] = ['header' => FALSE, 'data' => $this->t('@row.', ['@row' => $i]), 'rowspan' => count($rule->getConditions()) + 1];
      foreach ($rule->getConditions() as $condition_storage) {
        $k++;
        $row[] = ['header' => TRUE, 'data' => $this->t('Condition #@condition', ['@condition' => $k])];
        $condition = $condition_storage->createInstance();
        $condition_context += array_keys($condition->getContextDefinitions());
        $scope_all = (!in_array('registration', $condition_context) || in_array('event', $condition_context));
        if (isset($row['rule']['rowspan']) && $scope_all) {
          $row['rule']['rowspan']++;
        }

        if ($condition instanceof RNGConditionInterface) {
          $supports_create++;
        }
        $row[] = ['colspan' => 5, 'data' => $condition->summary()];

        $row['condition_operations']['data'] = ['#type' => 'operations'];
        if ($condition_storage->access('edit')) {
          $row['condition_operations']['data']['#links']['edit'] = [
            'title' => t('Edit'),
            'url' => $condition_storage->urlInfo('edit-form'),
            'query' => $destination,
          ];
        }

        $rows[] = ['data' => $row, 'no_striping' => TRUE];
        $row = [];
      }

      // Actions
      foreach ($rule->getActions() as $action_storage) {
        $row = [];
        $row[] = ['header' => TRUE, 'data' => $this->t('Grants operations'), 'rowspan' => $scope_all ? 2 : 1];

        // Scope: warn user actions apply to all registrations
        $row[]['data'] = $scope_all ? $this->t('All registrations.') : $this->t('Single registration');

        // Operations granted
        $config = $action_storage->getConfiguration();
        foreach ($operations as $op => $t) {
          $message = !empty($config['operations'][$op]) ? $t : '-';
          $row['operation_' . $op] = ['data' => ($op == 'create' && ($supports_create != count($rule->getConditions()))) ? $this->t('<em>N/A</em>') : $message];
        }

        $links = [];
        if ($action_storage->access('edit')) {
          $links['edit'] = [
            'title' => t('Edit'),
            'url' => $action_storage->urlInfo('edit-form'),
            'query' => $destination,
          ];
        }

        $row[] = ['data' => ['#type' => 'operations', '#links' => $links], 'rowspan' => $scope_all ? 2 : 1];
        $rows[] = $row;

        if ($scope_all) {
          $rows[] = [[
            'data' => $this->t('<strong>Warning:</strong> selecting view, update, or delete grants access to any registration on this event.'),
            'colspan' => 5,
          ]];
        }
      }
    }

    $build[] = [
      '#type' => 'table',
      '#header' => [],
      '#rows' => $rows,
      '#empty' => $this->t('No access rules.'),
    ];

    return $build;
  }

  /**
   * Displays a list of actions which are message or communication related.
   *
   * @param RouteMatchInterface $route_match
   *   The current route.
   * @param string $event
   *   The parameter to find the event entity.
   *
   * @return array
   *   A render array.
   */
  public function listing_messages(RouteMatchInterface $route_match, $event) {
    $event = $route_match->getParameter($event);
    $destination = drupal_get_destination();
    $build = array();
    $header = array(t('When'), t('Do'), t('Operations'));
    $rows = array();

    // list of communication related action plugin ids
    $communication_actions = array('rng_registrant_email');

    $rules = $this->eventManager->getMeta($event)->getRules();
    foreach($rules as $rule) {
      /* @var \Drupal\rng\RuleInterface $rule */
      foreach ($rule->getActions() as $action) {
        $row = array();
        $action_id = $action->getPluginId();
        if (in_array($action_id, $communication_actions)) {
          $definition = $this->actionManager->getDefinition($action_id);
          $row['trigger'] = $rule->getTriggerID();
          $row['action']['data'] = $definition['label'];

          $row['operations']['data'] = ['#type' => 'operations'];
          if ($action->access('edit')) {
            $row['operations']['data']['#links']['edit'] = [
              'title' => t('Edit'),
              'url' => $action->urlInfo('edit-form'),
              'query' => $destination,
            ];
          }
          if ($rule->access('delete')) {
            $row['operations']['data']['#links']['delete'] = [
              'title' => t('Delete'),
              'url' => $rule->urlInfo('delete-form'),
              'query' => $destination,
            ];
          }
        }
        else {
          continue;
        }

        $rows[] = $row;
      }
    }

    $build['description'] = [
      '#prefix' => '<p>',
      '#markup' => $this->t('These messages will be sent when a trigger occurs.'),
      '#suffix' => '</p>',
    ];

    $build['action_list'] = array(
      '#type' => 'table',
      '#header' => $header,
      '#title' => t('Messages'),
      '#rows' => $rows,
      '#empty' => $this->t('No messages found.'),
    );

    return $build;
  }
}