<?php

/**
 * Doctrine form base class that makes it pretty easy to embed one or multiple related forms including creation forms.
 *
 * @package    ahDoctrineEasyEmbeddedRelationsPlugin
 * @subpackage form
 * @author     Daniel Lohse, Steve Guhr <info@asaphosting.de>
 */
abstract class ahBaseFormDoctrine extends sfFormDoctrine
{
  protected
    $scheduledForDeletion = array(), // related objects scheduled for deletion
    $embedRelations = array();       // so we can check which relations are embedded in this form
  
  public function embedRelations(array $relations)
  {
    $this->embedRelations = $relations;
    
    $this->getEventDispatcher()->connect('form.post_configure', array($this, 'listenToFormPostConfigureEvent'));
    
    foreach (array_keys($relations) as $relationName)
    {
      $relation = $this->getObject()->getTable()->getRelation($relationName);
      if (!isset($relations[$relationName]['noNewForm']) || !$relations[$relationName]['noNewForm'])
      {
        if (($relation->isOneToOne() && !$this->getObject()->relatedExists($relationName)) || !$relation->isOneToOne())
        {
          $formClass = !isset($relations[$relationName]['newFormClass']) ? $relation->getClass().'Form' : $relations[$relationName]['newFormClass'];
          $formArgs = !isset($relations[$relationName]['newFormClassArgs']) ? array() : $relations[$relationName]['newFormClassArgs'];
          $r = new ReflectionClass($formClass);
          
          //sfContext::getInstance()->getLogger()->info($relation);
          
          if (Doctrine_Relation::MANY === $relation->getType())
          {
            $newFormObjectClass = $relation->getClass();
            $newFormObject = new $newFormObjectClass();
            $newFormObject[get_class($this->getObject())] = $this->getObject();
          } else
          {
            $newFormObject = $this->getObject()->$relationName;
          }
          
          $newForm = $r->newInstanceArgs(array_merge(array($newFormObject), array($formArgs)));
          $newFormIdentifiers = $newForm->getObject()->getTable()->getIdentifierColumnNames();
          foreach ($newFormIdentifiers as $primaryKey)
          {
            unset($newForm[$primaryKey]);
          }
          unset($newForm[$relation->getForeignColumnName()]);
          
          // FIXME/TODO: check if this even works for one-to-one
          // CORRECTION 1: Not really, it creates another record but doesn't link it to this object!
          // CORRECTION 2: No, it can't, silly! For that to work the id of the not-yet-existant related record would have to be known...
          // Think about overriding the save method and after calling parent::save($con) we should update the relations that:
          //   1. are one-to-one AND
          //   2. are LocalKey :)
          if (isset($relations[$relationName]['newFormLabel']))
          {
            $newForm->getWidgetSchema()->setLabel($relations[$relationName]['newFormLabel']);
          }
          
          $this->embedForm('new_'.$relationName, $newForm);
        }
      }
      
      $formClass = !isset($relations[$relationName]['formClass']) ? null : $relations[$relationName]['formClass'];
      $formArgs = array_merge((!isset($relations[$relationName]['formClassArgs']) ? array() : $relations[$relationName]['formClassArgs']), array(array('ah_add_delete_checkbox' => true)));
      $this->embedRelation($relationName, $formClass, $formArgs);
      
      /*
       * Unset the relation form(s) if:
       * (1. One-to-many relation and there are no related objects yet (count of embedded forms is 0) OR
       * 2. One-to-one relation and embedded form is new (no related object yet))
       * AND
       * (3. Option `displayEmptyRelations` was either not set by the user or was set by the user and is false)
       */
      if (
        (
          ((!$relation->isOneToOne()) && (count($this->getEmbeddedForm($relationName)->getEmbeddedForms()) === 0)) ||
          ($relation->isOneToOne() && $this->getEmbeddedForm($relationName)->isNew())
        ) && 
        (!isset($relations[$relationName]['displayEmptyRelations']) || !$relations[$relationName]['displayEmptyRelations'])
      )
      {
        unset($this[$relationName]);
      }
    }
    
    $this->getEventDispatcher()->disconnect('form.post_configure', array($this, 'listenToFormPostConfigureEvent'));
  }
  
  public function listenToFormPostConfigureEvent(sfEvent $event)
  {
    $form = $event->getSubject();
    
    if($form instanceof sfFormDoctrine && $form->getOption('ah_add_delete_checkbox', false) && !$form->isNew())
    {
      $form->setWidget('delete_object', new sfWidgetFormInputCheckbox(array('label' => 'Delete')));
      $form->setValidator('delete_object', new sfValidatorPass());
      
      return $form;
    }
    
    return false;
  }
  
  /**
   * Here we just drop the embedded creation forms if no value has been provided for them (this simulates a non-required embedded form),
   * please provide the fields for the related embedded form in the call to $this->embedRelations() so we don't throw validation errors
   * if the user did not want to add a new related object
   *
   * @see sfForm::doBind()
   */
  protected function doBind(array $values)
  {
    foreach ($this->embedRelations as $relationName => $keys)
    {
      if (!isset($keys['noNewForm']) || !$keys['noNewForm'])
      {
        if (isset($keys['considerNewFormEmptyFields']) && count($keys['considerNewFormEmptyFields']) > 0 && isset($values['new_'.$relationName]))
        {
          $emptyFields = 0;
          foreach ($keys['considerNewFormEmptyFields'] as $key)
          {
            if ('' === trim($values['new_'.$relationName][$key])) {
              $emptyFields++;
            } elseif (is_array($values['new_'.$relationName][$key]) && count($values['new_'.$relationName][$key]) === 0) {
              $emptyFields++;
            }
          }
          
          if ($emptyFields === count($keys['considerNewFormEmptyFields'])) {
            sfContext::getInstance()->getLogger()->info('Dropping relation :'.$relationName);
            unset($values['new_'.$relationName], $this['new_'.$relationName]);
          }
        }
      }
      
      if (isset($values[$relationName]))
      {
        $oneToOneRelationFix = $this->getObject()->getTable()->getRelation($relationName)->isOneToOne() ? array($values[$relationName]) : $values[$relationName];
        foreach ($oneToOneRelationFix as $i => $relationValues)
        {
          if (isset($relationValues['delete_object']) && $relationValues['id'])
          {
            $this->scheduledForDeletion[$relationName][$i] = $relationValues['id'];
          }
        }
      }
    }
    
    parent::doBind($values);
  }
  
  /**
   * Updates object with provided values, dealing with eventual relation deletion
   *
   * @see sfFormDoctrine::doUpdateObject()
   */
  protected function doUpdateObject($values)
  {
    if (count($this->scheduledForDeletion))
    {
      foreach ($this->scheduledForDeletion as $relationName => $ids)
      {
        $relation = $this->getObject()->getTable()->getRelation($relationName);
        foreach ($ids as $index => $id)
        {
          if ($relation->isOneToOne()) {
            unset($values[$relationName]);
          }
          else {
            unset($values[$relationName][$index]);
          }
          
          if (!$relation->isOneToOne()) {
            unset($this->object[$relationName][$index]);
          }
          else {
            $this->object->clearRelated($relationName);
          }
          
          Doctrine::getTable($relation->getClass())->findOneById($id)->delete();
        }
      }
    }
    
    parent::doUpdateObject($values);
  }
  
  /**
   * Saves embedded form objects.
   * TODO: Check if it's possible to use embedRelations in one form and and also use embedRelations in the embedded form!
   *       This means this would be possible:
   *         1. Edit a user object via the userForm and 
   *         2. Embed the groups relation (user-has-many-groups) into the groupsForm and embed that into userForm and 
   *         2. Embed the permissions relation (group-has-many-permissions) into the groupsForm and
   *         3. Just for kinks, embed the permissions relation again (user-has-many-permissions) into the userForm
   *
   * @param mixed $con   An optional connection object
   * @param array $forms An array of sfForm instances
   *
   * @see sfFormObject::saveEmbeddedForms()
   */
  public function saveEmbeddedForms($con = null, $forms = null)
  {
    if (null === $con) $con = $this->getConnection();
    if (null === $forms) $forms = $this->getEmbeddedForms();
    
    foreach ($forms as $form)
    {
      if ($form instanceof sfFormObject)
      {
        /** 
         * we know it's a form but we don't know what (embedded) relation it represents; 
         * this is necessary because we only care about the relations that we(!) embedded 
         * so there isn't anything weird happening
         */
        $relationName = $this->getRelationByEmbeddedFormClass($form);
        
        //sfContext::getInstance()->getLogger()->info(print_r($this->scheduledForDeletion, true));
        //sfContext::getInstance()->getLogger()->info($relationName);
        
        if (!$relationName || !isset($this->scheduledForDeletion[$relationName]) || ($relationName && !array_key_exists($form->getObject()->getId(), array_flip($this->scheduledForDeletion[$relationName]))))
        {
          $form->saveEmbeddedForms($con);
          $form->getObject()->save($con);
        }
      }
      else
      {
        $this->saveEmbeddedForms($con, $form->getEmbeddedForms());
      }
    }
  }
  
  /**
   * Get the used relation alias when given an embedded form
   *
   * @param sfForm $form A BaseForm instance
   */
  private function getRelationByEmbeddedFormClass($form)
  {
    foreach ($this->getObject()->getTable()->getRelations() as $relation)
    {
      if ($relation->getClass() === get_class($form->getObject()))
      {
        return $relation->getAlias();
      }
    }
    
    return false;
  }
}
