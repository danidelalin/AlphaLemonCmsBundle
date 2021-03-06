<?php
/*
 * This file is part of the AlphaLemon CMS Application and it is distributed
 * under the GPL LICENSE Version 2.0. To use this application you must leave
 * intact this copyright notice.
 *
 * Copyright (c) AlphaLemon <webmaster@alphalemon.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * For extra documentation and help please visit http://www.alphalemon.com
 * 
 * @license    GPL LICENSE Version 2.0
 * 
 */

namespace AlphaLemon\AlphaLemonCmsBundle\Core\Content\Language;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception;

use AlphaLemon\AlphaLemonCmsBundle\Core\Model\AlPageAttributeQuery;
use AlphaLemon\AlphaLemonCmsBundle\Core\Model\AlContentQuery;
use AlphaLemon\AlphaLemonCmsBundle\Model\AlContent;
use AlphaLemon\AlphaLemonCmsBundle\Model\AlLanguage;
use AlphaLemon\AlphaLemonCmsBundle\Core\Model\AlLanguageQuery;

use AlphaLemon\AlphaLemonCmsBundle\Core\Content\AlContentManagerInterface;
use AlphaLemon\AlphaLemonCmsBundle\Core\Content\Base\AlContentManagerBase;
use AlphaLemon\AlphaLemonCmsBundle\Core\Event\Content\LanguageEvents;
use AlphaLemon\AlphaLemonCmsBundle\Core\Event\Content;
use AlphaLemon\PageTreeBundle\Core\Tools\AlToolkit;

/**
 * Defines the language content manager object, that implements the methods to manage an AlLanguage object
 *
 * @author AlphaLemon <info@alphalemon.com>
 */
class AlLanguageManager extends AlContentManagerBase implements AlContentManagerInterface
{
    protected $alLanguage = null;

    /**
     * {@inheritdoc}
     */
    public function get()
    {
        return $this->alLanguage;
    }

    /**
     * {@inheritdoc}
     */
    public function set(\BaseObject $propelObject = null)
    {
        if(null !== $propelObject && !$propelObject instanceof AlLanguage)
        {
            throw new \InvalidArgumentException('AlLanguageManager accepts only AlLanguage propel objects.');
        }

        $this->alLanguage = $propelObject;
    }

    /**
     * {@inheritdoc}
     */
    public function save(array $parameters)
    {
        if(null === $this->alLanguage)
        {
            return $this->add($parameters);
        }
        else
        {
            return $this->edit($parameters);
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function delete()
    {
        if (null === $this->alLanguage)
        {
            throw new \RuntimeException(AlToolkit::translateMessage($this->container, "Any language has been assigned to the LanguageManager. Delete operation aborted", array(), 'al_language_manager_exceptions'));
        }
        
        if ($this->alLanguage->getMainLanguage() == 1)
        {
            throw new \RuntimeException(AlToolkit::translateMessage($this->container, "The website main language cannot be deleted. To delete this language promote another one as main language, then delete it again", array(), 'al_language_manager_exceptions'));
        }
        
        try
        {
            $dispatcher = $this->container->get('event_dispatcher');
            if(null !== $dispatcher)
            {
                $event = new  Content\Language\BeforeLanguageDeletingEvent($this);
                $dispatcher->dispatch(LanguageEvents::BEFORE_DELETE_LANGUAGE, $event);

                if($event->isAborted())
                {
                    throw new \RuntimeException(AlToolkit::translateMessage($this->container, "The language deleting action has been aborted", array(), 'al_language_manager_exceptions'));
                }
            }

            $rollBack = false;
            $this->connection->beginTransaction();

            $this->alLanguage->setToDelete(1);
            $result = $this->alLanguage->save();
            if ($this->alLanguage->isModified() && $result == 0)
            {
                $rollBack = true;
            }
            else
            {
                $rollBack = !$this->deleteBlocksAndPageAttributes();
            }

            if (!$rollBack)
            {
                $this->connection->commit();

                if(null !== $dispatcher)
                {
                    $event = new  Content\Language\AfterLanguageDeletedEvent($this);
                    $dispatcher->dispatch(LanguageEvents::AFTER_DELETE_LANGUAGE, $event);
                }

                return true;
            }
            else
            {
                $this->connection->rollback();
                return false;
            }
        }
        catch(Exception $e)
        {
            if(isset($this->connection) && $this->connection !== null) $this->connection->rollback();
            throw $e;
        }    
    }
    
    /**
     * Adds a new AlLanguage object from the given params
     * 
     * @param array $values
     * @return Boolean
     */
    protected function add(array $values)
    {
        try
        {
            $dispatcher = $this->container->get('event_dispatcher');
            if(null !== $dispatcher)
            {
                $event = new  Content\Language\BeforeLanguageAddingEvent($this, $values);
                $dispatcher->dispatch(LanguageEvents::BEFORE_ADD_LANGUAGE, $event);

                if($event->isAborted())
                {
                    throw new \RuntimeException(AlToolkit::translateMessage($this->container, "The language adding action has been aborted", array(), 'al_language_manager_exceptions'));
                }

                if($values !== $event->getValues())
                {
                    $values = $event->getValues();
                }
            }

            $result = false;
            if(empty($values))
            {
                throw new \InvalidArgumentException($this->container->get('translator')->trans('The language cannot be added because any parameter has been given'));
            }

            $this->checkRequiredParamsExists(array('language' => ''), $values);

            // Checks if page already exists
            $language = AlLanguageQuery::create()
                                        ->setContainer($this->container)
                                        ->fromLanguageName($values["language"])
                                        ->findOne();
            if ($language != null)
            {
                throw new \InvalidArgumentException($this->container->get('translator')->trans("The language you are trying to add, already exists in the website."));
            }

        
            $rollback = false;
            $this->connection->beginTransaction();

            // Retrieves the site's main language
            $mainLanguage = AlLanguageQuery::create()->mainLanguage()->findOne();
            $currentLanguageId = (null !== $mainLanguage) ? $mainLanguage->getId() : 0;

            // Resets the column mainLanguage is the new one will be the main language
            $isMain = ($currentLanguageId == 0) ? 1 : 0; 
            if (array_key_exists("isMain", $values) && $values["isMain"] == 1)
            {
                $rollback = !$this->resetMain();
                $isMain = $values["isMain"];
            }
            
            if (!$rollback)
            {
                // Saves the language
                $this->alLanguage = new AlLanguage();
                $this->alLanguage->setLanguage($values["language"]);
                $this->alLanguage->setMainLanguage($isMain);
                $result = $this->alLanguage->save();
                if ($this->alLanguage->isModified() && $result == 0)
                {
                    $rollback = true;
                }
                else
                {
                    $rollback = !$this->addPageAttributesAndBlocks($currentLanguageId);
                }
            }

            if (!$rollback)
            {
                $this->connection->commit();
                
                if(null !== $dispatcher)
                {
                    $event = new  Content\Language\AfterLanguageAddedEvent($this);
                    $dispatcher->dispatch(LanguageEvents::AFTER_ADD_LANGUAGE, $event);
                }
                
                return true;
            }
            else
            {
                $this->connection->rollback();
                return false;
            }
        }
        catch(\Exception $e)
        {
            if(isset($this->connection) && $this->connection !== null) $this->connection->rollback();
            throw $e;
        }
    }

    /**
     * Adds the page attribute and contents for the language identified by the given param
     * 
     * @param int $idLanguage The current language's id
     * @return Boolean 
     */
    protected function addPageAttributesAndBlocks($idLanguage)
    {
        try
        {
            $rollBack = false;
            $this->connection->beginTransaction();
            
            // Copies the page attributes to the new language
            $alPageAttributesManager = $this->container->get('al_page_attributes_manager');
            $pagesAttributes = AlPageAttributeQuery::create()
                                ->setContainer($this->container)
                                ->fromLanguageId($idLanguage)
                                ->find();
            foreach($pagesAttributes as $pageAttributes)
            {
                $values = array('idPage' => $pageAttributes->getPageId(),
                                'idLanguage' => $this->alLanguage->getId(),
                                'permalink' => $pageAttributes->getPermalink(),
                                'languageName' => $this->alLanguage->getLanguage(),
                                'title' => $pageAttributes->getMetaTitle(),
                                'description' => $pageAttributes->getMetaDescription(),
                                'keywords' => $pageAttributes->getMetaKeywords());                
                $alPageAttributesManager->set(null);
                $rollBack = !$alPageAttributesManager->save($values);
                if($rollBack)
                {
                    return false;
                }
            }
            
            // Copies the contents to the new language
            $contents = AlContentQuery::create()
                                ->setContainer($this->container)
                                ->fromLanguageId($idLanguage)
                                ->find();
            foreach($contents as $content)
            {
                $newContent = new AlContent();
                $values = $content->toArray();
                unset($values['Id']);
                unset($values['CreatedAt']);
                $values['HtmlContent'] = $this->fixInternalLinks($values['HtmlContent']);
                $newContent->fromArray($values);
                $newContent->setLanguageId($this->alLanguage->getId());
                $newContent->save($this->connection);
            }

            if (!$rollBack)
            {
              $this->connection->commit();
              return true;
            }
            else
            {
              $this->connection->rollback();
              return false;
            }
        }
        catch(\Exception $e)
        {
            if(isset($this->connection) && $this->connection !== null) $this->connection->rollback();
            throw $e;
        }
    }
    
    /**
     * Fixes all the internal links according with the new language
     * 
     * @param string $content
     * @return string 
     */
    protected function fixInternalLinks($content)
    {
        $container = $this->container;
        $languageName =  $this->alLanguage->getLanguage();
        $content = preg_replace_callback('/(\<a[\s+\w+]href=[\"\'])(.*?)([\"\'])/s', function ($matches) use($container, $languageName) {
            
            $url = $matches[2];
            try
            {
                $tmpUrl = (empty($match) && substr($url, 0, 1) != '/') ? '/' . $url : $url;
                $params = $container->get('router')->match($tmpUrl); 
                
                $url = (!empty($params)) ? $languageName . '-' . $url : $url;
            }
            catch(\Symfony\Component\Routing\Exception\ResourceNotFoundException $ex)
            {
                // Not internal route the link remains the same
            }
            
            return $matches[1] . $url . $matches[3];
        }, $content);
        
        return $content;
    }
    
    /**
     * Edits the managed language object
     * 
     * @param array $values
     * @return Boolean
     */
    protected function edit(array $values)
    {
        try
        {
            $dispatcher = $this->container->get('event_dispatcher');
            if(null !== $dispatcher)
            {
                $event = new  Content\Language\BeforeLanguageEditingEvent($this, $values);
                $dispatcher->dispatch(LanguageEvents::BEFORE_EDIT_LANGUAGE, $event);
            
                if($event->isAborted())
                {
                    throw new \RuntimeException(AlToolkit::translateMessage($this->container, "The language editing action has been aborted", array(), 'al_language_manager_exceptions'));
                }

                if($values !== $event->getValues())
                {
                    $values = $event->getValues();
                }
            }
            
            $this->checkEmptyParams($values);            
            $this->checkOnceValidParamExists(array('language' => '', 'isMain' => ''), $values);
            
            $rollBack = false;
            $this->connection->beginTransaction();

            $isMainChanged = false;
            if(isset($values["isMain"]) && $values["isMain"] == 1)
            {
                if($this->alLanguage->getMainLanguage() != $values["isMain"])
                {
                    // If the language is declared as main, resets the previuos
                    $rollBack = !$this->resetMain();
                    if (!$rollBack) $this->alLanguage->setMainLanguage($values["isMain"]);
                    $isMainChanged = true;
                }
            }

            if (!$rollBack)
            {
                if(array_key_exists('language', $values) && $this->alLanguage->getLanguage() != $values["language"]) $this->alLanguage->setLanguage($values["language"]);

                // Skips the control when the isMain param has been changed
                If(!$isMainChanged && null !== AlLanguageQuery::create()->fromLanguageName($values["language"])->findOne())
                {
                    throw new \RuntimeException('There\'s nothing to edit, because a language which has the given parameters already exist');
                }

                $this->result = $this->alLanguage->save();
                if ($this->alLanguage->isModified() && $this->result == 0) $rollBack = true;
            }

            if (!$rollBack)
            {
                $this->connection->commit();
                
                if(null !== $dispatcher)
                {
                    $event = new  Content\Language\AfterLanguageEditedEvent($this);
                    $dispatcher->dispatch(LanguageEvents::AFTER_EDIT_LANGUAGE, $event);
                }
                
                return true;
            }
            else
            {
                $this->connection->rollback();
                return false;
            }
        }
        catch(\Exception $e)
        {
            if(isset($this->connection) && $this->connection !== null) $this->connection->rollback();
            throw $e;
        }
    }

    /**
     * Deletes the blocks and page attributes for the current language
     * 
     * @return type 
     */
    protected function deleteBlocksAndPageAttributes()
    {
        try
        {
            $rollBack = false;
            $this->connection->beginTransaction();

            $contents = AlContentQuery::create()
                        ->setContainer($this->container)
                        ->fromLanguageId($this->alLanguage->getId())
                        ->find();
            foreach($contents as $content)
            {
                $content->setToDelete(1);
                $result = $content->save();
                if ($content->isModified() && $result == 0)
                {
                    $rollBack = true;
                    break;
                }
            }
            
            $pageAttributes = AlPageAttributeQuery::create()
                                ->setContainer($this->container)
                                ->fromLanguageId($this->alLanguage->getId())
                                ->find();
            foreach($pageAttributes as $pageAttribute)
            {
                $pageAttribute->setToDelete(1);
                $result = $pageAttribute->save();
                if ($pageAttribute->isModified() && $result == 0)
                {
                    $rollBack = true;
                    break;
                }
            }

            if (!$rollBack)
            {
                $this->connection->commit();
                return true;
            }
            else
            {
                $this->connection->rollback();
                return false;
            }
        }
        catch(\Exception $e)
        {
            if(isset($this->connection) && $this->connection !== null) $this->connection->rollback();
            throw $e;
        }
    }

    /**
     * Degrades the main language to normal
     * 
     * @return bool 
     */
    protected function resetMain()
    {
        try
        {
            $mainLanguage = AlLanguageQuery::create()->setContainer($this->container)->mainLanguage()->findOne();
            if(null !== $mainLanguage)
            {
                $mainLanguage->setMainLanguage(0);
                $result = $mainLanguage->save();

                return ($mainLanguage->isModified() && $result == 0) ? false : true;
            }
            
            return true;
        }
        catch(Exception $e)
        {
            if(isset($this->connection) && $this->connection !== null) $this->connection->rollback();
            throw new $e;
        }
    }
}