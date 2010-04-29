<?php

/**
 * Class used to embed new object forms in parent form
 *
 * @package    ahDoctrineEasyEmbeddedRelationsPlugin
 * @subpackage form
 * @author     Krzysztof Kotowicz <kkotowicz at gmail dot com>
 */
class ahNewRelationsContainerForm extends BaseForm
{
  public function configure()
  {
    $button = new ahNewRelationField(array(
      'containerName' => $this->getOption('containerName'), 
      'addJavascript' => $this->getOption('addByCloning'),
      'useJSFramework' => $this->getOption('useJSFramework')
    ));

    $this->setWidget('_', $button);
  }
  
  /**
   * Moves button below embedded forms
   * @inheritdoc
   * @param string $name
   * @param sfForm $form
   * @param string $decorator
   */
  public function embedForm($name, sfForm $form, $decorator = null)
  {
    parent::embedForm($name, $form, $decorator);
    $this->widgetSchema->moveField('_', sfWidgetFormSchema::LAST);
  }
}