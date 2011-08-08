<?php

/**
 * Easily adds sorting functionality to a record.
 *
 * @package     csDoctrineSortablePlugin
 * @subpackage  template
 * @license     http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link        www.phpdoctrine.org
 * @since       1.0
 * @version     $Revision$
 * @author      Travis Black <tblack@centresource.com>
 */
class Doctrine_Template_Sortable extends Doctrine_Template
{
  /**
   * Array of Sortable options
   *
   * @var string
   */
  protected
    $_defaultOptions = array(
      'name'        =>  'position',
      'alias'       =>  null,
      'type'        =>  'integer',
      'length'      =>  8,
      'unique'      =>  true,
      'options'     =>  array(),
      'fields'      =>  array(),
      'uniqueBy'    =>  array(),
      'uniqueIndex' =>  true,
      'indexName'   =>  'sortable'
    )
  , $_options = array()
  ;

  /**
   * __construct
   *
   * @param string $array
   * @return void
   */
  public function __construct(array $options = array())
  {
    //BC: accept single sortable definitions
    if(count($options) == 0)
    {
      $this->_options[0] = $this->_defaultOptions;
    }
    else
    {
      $isOldMode = false;
      foreach($options as $key => $value)
      {
        if(in_array($key, array_keys($this->_defaultOptions))) //is normal (old) style
        {
          $this->_options[0] = Doctrine_Lib::arrayDeepMerge($this->_defaultOptions, $options);
          $isOldMode = true;
          break;
        }
      }
      if(!$isOldMode)
      {
        foreach($options as $index => $_options)
        {
          if(!isset($_options['name']))
          {
            $_options['name'] = 'position_' . $index;
          }
          $this->_options[$index] = Doctrine_Lib::arrayDeepMerge($this->_defaultOptions, $_options);
        }
      }
    }
  }

  /**
   * Checks if array is multidimensional
   * Taken from:
   * http://stackoverflow.com/questions/145337/checking-if-array-is-multidimensional-or-not
   *
  **/
  private function _is_multi($array)
  {
    return (count($array) != count($array, COUNT_RECURSIVE));
  }


  /**
   * Set table definition for sortable behavior
   * (borrowed and modified from Sluggable in Doctrine core)
   *
   * @return void
   */
  public function setTableDefinition()
  {
    foreach($this->_options as $index => $options)
    {
      $this->_setTableDefinition($options, $index);
    }
  }

  private function _setTableDefinition($options, $index)
  {
    $name = $options['name'];

    if ($options['alias'])
    {
      $name .= ' as ' . $options['alias'];
    }

    $this->hasColumn($name, $options['type'], $options['length'], $options['options']);

    if (!empty($options['uniqueBy']) && !is_array($options['uniqueBy']))
    {
      throw new sfException("Sortable option 'uniqueBy' must be an array");
    }

    if ($options['uniqueIndex'] == true && ! empty($options['uniqueBy']))
    {
      $indexFields = array($options['name']);
      $indexFields = array_merge($indexFields, $options['uniqueBy']);

      $this->index($this->getSortableIndexName($index), array('fields' => $indexFields, 'type' => 'unique'));

    }
    elseif ($options['unique'])
    {
      $indexFields = array($options['name']);
      $this->index($this->getSortableIndexName($index), array('fields' => $indexFields, 'type' => 'unique'));

    }

    $this->addListener(new Doctrine_Template_Listener_Sortable($options));
  }

  /**
  * Returns the name of the index to create for the position field.
  *
  * @return string
  */
  protected function getSortableIndexName($index = 0)
  {
    return sprintf('%s_%s_%s', $this->getTable()->getTableName(), $this->_options[$index]['name'], $this->_options[$index]['indexName']);
  }


  /**
   * Demotes a sortable object to a lower position
   *
   * @return void
   */
  public function demote($index = 0)
  {
    $object = $this->getInvoker();
    $position = $object->get($this->_options[$index]['name']);

    if ($position < $object->getFinalPosition($index))
    {
      $object->moveToPosition($index, $position + 1);
    }
  }


  /**
   * Promotes a sortable object to a higher position
   *
   * @return void
   */
  public function promote($index = 0)
  {
    $object = $this->getInvoker();
    $position = $object->get($this->_options[$index]['name']);

    if ($position > 1)
    {
      $object->moveToPosition($index, $position - 1);
    }
  }

  /**
   * Sets a sortable object to the first position
   *
   * @return void
   */
  public function moveToFirst($index = 0)
  {
    $object = $this->getInvoker();
    $object->moveToPosition($index, 1);
  }


  /**
   * Sets a sortable object to the last position
   *
   * @return void
   */
  public function moveToLast($index = 0)
  {
    $object = $this->getInvoker();
    $object->moveToPosition($index, $object->getFinalPosition($index));
  }


  /**
   * Moves a sortable object to a designate position
   *
   * @param int $newPosition
   * @return void
   */
  public function moveToPosition($newPosition, $index = 0)
  {
    if(null == $index)
    {
      throw new Doctrine_Exception('moveToPosition requires the index of the sortable field.');
    }
    if (!is_int($newPosition))
    {
      throw new Doctrine_Exception('moveToPosition requires an Integer as the new position. Entered ' . $newPosition);
    }

    $object = $this->getInvoker();
    $position = $object->get($this->_options[$index]['name']);
    $conn = $object->getTable()->getConnection();

    //begin Transaction
    $conn->beginTransaction();

    // Position is required to be unique. Blanks it out before it moves others up/down.
    $object->set($this->_options[$index]['name'], null);
    $object->save();

    if ($position > $newPosition)
    {
      $q = $object->getTable()->createQuery()
                              ->where($this->_options[$index]['name'] . ' < ?', $position)
                              ->andWhere($this->_options[$index]['name'] . ' >= ?', $newPosition)
                              ->orderBy($this->_options[$index]['name'] . ' DESC');

      foreach ($this->_options[$index]['uniqueBy'] as $field)
      {
        $q->addWhere($field . ' = ?', $object[$field]);
      }

      // some drivers do not support UPDATE with ORDER BY query syntax
      if ($this->canUpdateWithOrderBy($conn))
      {
        $q->update(get_class($object))
          ->set($this->_options[$index]['name'], $this->_options[$index]['name'] . ' + 1')
          ->execute();
      }
      else
      {
        foreach ( $q->execute() as $item )
        {
          $pos = $item->get($this->_options[$index]['name'] );
          $item->set($this->_options[$index]['name'], $pos+1)->save();
        }
      }

    }
    elseif ($position < $newPosition)
    {

      $q = $object->getTable()->createQuery()
                              ->where($this->_options[$index]['name'] . ' > ?', $position)
                              ->andWhere($this->_options[$index]['name'] . ' <= ?', $newPosition)
                              ->orderBy($this->_options[$index]['name'] . ' ASC');

      foreach($this->_options[$index]['uniqueBy'] as $field)
      {
        $q->addWhere($field . ' = ?', $object[$field]);
      }

      // some drivers do not support UPDATE with ORDER BY query syntax
      if ($this->canUpdateWithOrderBy($conn))
      {
        $q->update(get_class($object))
          ->set($this->_options[$index]['name'], $this->_options[$index]['name'] . ' - 1')
          ->execute();
      }
      else
      {
        foreach ( $q->execute() as $item )
        {
          $pos = $item->get($this->_options[$index]['name'] );
          $item->set($this->_options[$index]['name'], $pos-1)->save();
        }
      }

    }

    $object->set($this->_options[$index]['name'], $newPosition);
    $object->save();

    // Commit Transaction
    $conn->commit();
  }


  /**
   * Send an array from the sortable_element tag (symfony+prototype)and it will
   * update the sort order to match
   *
   * @param string $order
   * @return void
   * @author Travis Black
   */
  public function sortTableProxy($order, $index = 0)
  {
    /*
      TODO
        - Add proper error messages.
    */
    $table = $this->getInvoker()->getTable();
    $class  = get_class($this->getInvoker());
    $conn = $table->getConnection();

    $conn->beginTransaction();

    foreach ($order as $position => $id)
    {
      $newObject = Doctrine::getTable($class)->findOneById($id);

      if ($newObject->get($this->_options[$index]['name']) != $position + 1)
      {
        $newObject->moveToPosition($index, $position + 1);
      }
    }

    // Commit Transaction
    $conn->commit();
  }


  /**
   * Finds all sortable objects and sorts them based on position attribute
   * Ascending or Descending based on parameter
   *
   * @param string $order
   * @return $query
   */
  public function findAllSortedTableProxy($order = 'ASC', $index = 0)
  {
    $order = $this->formatAndCheckOrder($order);
    $object = $this->getInvoker();

    $query = $object->getTable()->createQuery()
                                ->orderBy($this->_options[$index]['name'] . ' ' . $order);

    return $query->execute();
  }


  /**
   * Finds and returns records sorted where the parent (fk) in a specified
   * one to many relationship has the value specified
   *
   * @param string $parentValue
   * @param string $parent_column_value
   * @param string $order
   * @return $query
   */
  public function findAllSortedWithParentTableProxy($parentValue, $parentColumnName = null, $order = 'ASC', $index = 0)
  {
    $order = $this->formatAndCheckOrder($order);

    $object = $this->getInvoker();
    $class  = get_class($object);

    if (!$parentColumnName)
    {
      $parents = get_class($object->getParent());

      if (count($parents) > 1)
      {
        throw new Doctrine_Exception('No parent column name specified and object has mutliple parents');
      }
      elseif (count($parents) < 1)
      {
        throw new Doctrine_Exception('No parent column name specified and object has no parents');
      }
      else
      {
        $parentColumnName = $parents[0]->getType();
        exit((string) $parentColumnName);
        exit(print_r($parents[0]->toArray()));
      }
    }

    $query = $object->getTable()->createQuery()
                                ->from($class . ' od')
                                ->where('od.' . $parentColumnName . ' = ?', $parentValue)
                                ->orderBy($this->_options[$index]['name'] . ' ' . $order);

    return $query->execute();
  }


  /**
   * Formats the ORDER for insertion in to query, else throws exception
   *
   * @param string $order
   * @return $order
   */
  public function formatAndCheckOrder($order)
  {
    $order = strtolower($order);

    if ('ascending' === $order || 'asc' === $order)
    {
      $order = 'ASC';
    }
    elseif ('descending' === $order || 'desc' === $order)
    {
      $order = 'DESC';
    }
    else
    {
      throw new Doctrine_Exception('Order parameter value must be "asc" or "desc"');
    }

    return $order;
  }


  /**
   * Get the final position of a model
   *
   * @return int $position
   */
  public function getFinalPosition($index = 0)
  {
    $object = $this->getInvoker();

    $q = $object->getTable()->createQuery()
                            ->select($this->_options[$index]['name'])
                            ->orderBy($this->_options[$index]['name'] . ' desc');

   foreach($this->_options[$index]['uniqueBy'] as $field)
   {
     $value = (is_object($object[$field])) ? $object[$field]['id'] : $object[$field];
     if (!empty($value))
     {
      $q->addWhere($field . ' = ?', $value);
     }
     else
     {
      $q->addWhere('(' . $field . ' = ? OR ' . $field . ' IS NULL)', $value);
     }
   }

   $last = $q->limit(1)->fetchOne();
   $finalPosition = $last ? $last->get($this->_options[$index]['name']) : 0;

   return (int)$finalPosition;
  }

  // sqlite/pgsql doesn't supports UPDATE with ORDER BY
  protected function canUpdateWithOrderBy(Doctrine_Connection $conn)
  {
    // If transaction level is greater than 1,
    // query will throw exceptions when using this function
    return $conn->getTransactionLevel() < 2 &&
      // some drivers do not support UPDATE with ORDER BY query syntax
      $conn->getDriverName() != 'Pgsql' && $conn->getDriverName() != 'Sqlite';
  }
}