<?php

/**
 * This class displays the button to add new embedded relation forms; it relies on client-side JavaScript to work.
 * @author     Krzysztof Kotowicz <kkotowicz at gmail dot com>
 */
class ahNewRelationField extends sfWidgetForm
{

  protected function configure($options = array(), $attributes = array())
  {
    $this->addRequiredOption('containerName');
    $this->addOption('addJavascript', false);
    $this->addOption('useJSFramework', 'jQuery');
  }

  public function render($name, $value = null, $attributes = array(), $errors = array())
  {
    return $this->renderContentTag('button', $value !== null ? $value : '+', array('type' => 'button', 'class' => 'ahAddRelation', 'rel' => $this->getOption('containerName')));
  }

  public function getJavaScripts()
  {
    if (false === $this->getOption('addJavascript')) return array();
    
    return array(sprintf('/ahDoctrineEasyEmbeddedRelationsPlugin/js/ahDoctrineEasyEmbeddedRelationsPlugin.%s.js', $this->getOption('addJavascript')));
  }
}