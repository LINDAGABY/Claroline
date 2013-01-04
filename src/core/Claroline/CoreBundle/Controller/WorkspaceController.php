<?php

namespace Claroline\CoreBundle\Controller;

use Claroline\CoreBundle\Form\WorkspaceType;
use Claroline\CoreBundle\Library\Workspace\Configuration;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Claroline\CoreBundle\Library\Widget\Event\DisplayWidgetEvent;
use Claroline\CoreBundle\Entity\Widget\DisplayConfig;
use Claroline\CoreBundle\Form\ResourceRightsType;
use Claroline\CoreBundle\Library\Widget\Event\ConfigureWidgetWorkspaceEvent;

/**
 * This controller is able to:
 * - list/create/delete/show workspaces.
 * - return some users/groups list (ie: (un)registered users to a workspace).
 * - add/delete users/groups to a workspace.
 */
class WorkspaceController extends Controller
{
    const ABSTRACT_WS_CLASS = 'Claroline\CoreBundle\Entity\Workspace\AbstractWorkspace';

    /**
     * Renders the workspace list page with its claroline layout.
     *
     * @throws AccessDeniedHttpException
     *
     * @return Response
     */
    public function listAction()
    {
        if (false === $this->get('security.context')->isGranted('ROLE_USER')) {
            throw new AccessDeniedHttpException();
        }

        $em = $this->get('doctrine.orm.entity_manager');
        $workspaces = $em->getRepository(self::ABSTRACT_WS_CLASS)->getNonPersonnalWS();

        return $this->render(
            'ClarolineCoreBundle:Workspace:list.html.twig',
            array('workspaces' => $workspaces)
        );
    }

    /**
     * Renders the registered workspace list for a user.
     *
     * @param integer $userId
     * @param string $format the format
     *
     * @throws AccessDeniedHttpException
     *
     * @return Response
     */
    public function listWorkspacesByUserAction($userId)
    {
        if (false === $this->get('security.context')->isGranted('ROLE_USER')) {
            throw new AccessDeniedHttpException();
        }

        $em = $this->get('doctrine.orm.entity_manager');
        $user = $em->find('Claroline\CoreBundle\Entity\User', $userId);
        $workspaces = $em->getRepository(self::ABSTRACT_WS_CLASS)->getWorkspacesOfUser($user);

        return $this->render(
            "ClarolineCoreBundle:Workspace:list.html.twig",
            array('workspaces' => $workspaces)
        );
    }

    /**
     * Renders the workspace creation form
     *
     * @return Response
     */
    public function creationFormAction()
    {
        if (false === $this->get('security.context')->isGranted('ROLE_WS_CREATOR')) {
            throw new AccessDeniedHttpException();
        }

        $form = $this->get('form.factory')->create(new WorkspaceType());

        return $this->render(
            'ClarolineCoreBundle:Workspace:form.html.twig',
            array('form' => $form->createView())
        );
    }

    /**
     * Create a workspace from a form sent by POST
     *
     * @return RedirectResponse
     *
     * @throws AccessDeniedHttpException
     */
    public function createAction()
    {
        if (false === $this->get('security.context')->isGranted('ROLE_WS_CREATOR')) {
            throw new AccessDeniedHttpException();
        }

        $form = $this->get('form.factory')->create(new WorkspaceType());
        $form->bindRequest($this->getRequest());

        if ($form->isValid()) {
            $type = $form->get('type')->getData() == 'simple' ?
                Configuration::TYPE_SIMPLE :
                Configuration::TYPE_AGGREGATOR;
            $config = new Configuration();
            $config->setWorkspaceType($type);
            $config->setWorkspaceName($form->get('name')->getData());
            $config->setWorkspaceCode($form->get('code')->getData());
            $user = $this->get('security.context')->getToken()->getUser();
            $wsCreator = $this->get('claroline.workspace.creator');
            $wsCreator->createWorkspace($config, $user);
            $route = $this->get('router')->generate('claro_workspace_list');

            return new RedirectResponse($route);
        }

        return $this->render(
            'ClarolineCoreBundle:Workspace:form.html.twig',
            array('form' => $form->createView())
        );
    }

    /**
     * Delete a workspace and redirects to the desktop_index
     *
     * @param integer $workspaceId
     *
     * @return RedirectResponse
     *
     * @throws AccessDeniedHttpException
     */
    public function deleteAction($workspaceId)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $workspace = $em->getRepository(self::ABSTRACT_WS_CLASS)->find($workspaceId);

        if (false === $this->get('security.context')->isGranted("ROLE_WS_MANAGER_{$workspaceId}", $workspace)) {
            throw new AccessDeniedHttpException();
        }

        $em->remove($workspace);
        $em->flush();

        return new Response('success', 204);
    }

    /**
     * Renders the home page with its layout.
     *
     * @param integer $workspaceId
     *
     * @return Response
     *
     * @throws AccessDeniedHttpException
     */
    public function homeAction($workspaceId)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $workspace = $em->getRepository(self::ABSTRACT_WS_CLASS)->find($workspaceId);
        $this->checkRegistration($workspace);

        return $this->render(
            'ClarolineCoreBundle:Workspace:home.html.twig',
            array('workspace' => $workspace)
        );
    }

    /**
     * Renders the resources page with its layout.
     *
     * @param integer $workspaceId
     *
     * @return Response
     *
     * @throws AccessDeniedHttpException
     */
    public function resourcesAction($workspaceId)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $workspace = $em->getRepository(self::ABSTRACT_WS_CLASS)->find($workspaceId);
        $this->checkRegistration($workspace);
        $directoryId = $em->getRepository('Claroline\CoreBundle\Entity\Resource\AbstractResource')
            ->getRootForWorkspace($workspace)
            ->getId();
        $resourceTypes = $em->getRepository('Claroline\CoreBundle\Entity\Resource\ResourceType')
            ->findBy(array('isVisible' => true));

        return $this->render(
            'ClarolineCoreBundle:Workspace:resources.html.twig',
            array(
                'workspace' => $workspace,
                'directoryId' => $directoryId,
                'resourceTypes' => $resourceTypes
            )
        );
    }

    //todo dql for this

    /**
     * Display registered widgets
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function widgetsAction($workspaceId)
    {
        $configs = $this->get('claroline.widget.manager')->generateWorkspaceDisplayConfig($workspaceId);
        $em = $this->getDoctrine()->getEntityManager();
        $workspace = $em->getRepository(self::ABSTRACT_WS_CLASS)->find($workspaceId);

        foreach ($configs as $config){
            if($config->isVisible()){
                $eventName = strtolower("widget_{$config->getWidget()->getName()}_workspace");
                $event = new DisplayWidgetEvent($workspace);
                $this->get('event_dispatcher')->dispatch($eventName, $event);
                $responsesString[strtolower($config->getWidget()->getName())] = $event->getContent();
            }
        }

        return $this->render('ClarolineCoreBundle:Widget:widgets.html.twig', array('widgets' => $responsesString));
    }

    /**
     * Renders the workspace properties page
     *
     * @param integer $workspaceId
     *
     * @return Response
     */
    public function propertiesAction($workspaceId)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $workspace = $em->getRepository(self::ABSTRACT_WS_CLASS)->find($workspaceId);

        return $this->render('ClarolineCoreBundle:Workspace:tools\properties.html.twig', array('workspace' => $workspace));
    }

    /**
     * Renders the workspace widget properties page.
     *
     * @param integer $workspaceId
     *
     * @return Response
     */
    public function widgetsPropertiesAction($workspaceId)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $workspace = $em->getRepository(self::ABSTRACT_WS_CLASS)->find($workspaceId);
        $configs = $this->get('claroline.widget.manager')->generateWorkspaceDisplayConfig($workspaceId);

        return $this->render('ClarolineCoreBundle:Workspace:tools\widget_properties.html.twig',
            array('workspace' => $workspace, 'configs' => $configs)
        );
    }

    /**
     * Renders the workspace roles configuration page.
     *
     * @param integer $workspaceId
     *
     * @return Response
     */
    public function configureRightsAction($workspaceId)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $workspace = $em->getRepository(self::ABSTRACT_WS_CLASS)->find($workspaceId);

        return $this->render('ClarolineCoreBundle:Workspace:tools\rights.html.twig', array('workspace' => $workspace));
    }

    /**
     * Renders the resource configuration for a specific role.
     *
     * @param integer $roleId
     *
     * @return Response
     */
    public function resourcesRightsFormAction($workspaceId)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $workspace = $em->getRepository(self::ABSTRACT_WS_CLASS)->find($workspaceId);
        $root = $em->getRepository('ClarolineCoreBundle:Resource\AbstractResource')->findOneBy(array('workspace' => $workspaceId, 'parent' => null));
        $configs = $em->getRepository('ClarolineCoreBundle:Workspace\ResourceRights')->findBy(array('resource' => $root));

        return $this->render('ClarolineCoreBundle:Workspace:tools\resources_rights.html.twig',
            array('workspace' => $workspace, 'configs' => $configs)
        );
    }

    /**
     * Edit the resources permissions
     *
     * @param integer $workspaceId
     *
     * @return Response
     */
    public function editResourcesRightsAction($workspaceId)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $workspace = $em->getRepository(self::ABSTRACT_WS_CLASS)->find($workspaceId);
        $root = $em->getRepository('ClarolineCoreBundle:Resource\AbstractResource')->findOneBy(array('workspace' => $workspace, 'parent' => null));
        $configs = $em->getRepository('ClarolineCoreBundle:Workspace\ResourceRights')->findBy(array('resource' => $root));
        $checks = $this->get('claroline.resource.rights')->setRightsRequest($this->get('request')->request->all());

        foreach($configs as $config){
            $config->reset();

            if(isset($checks[$config->getId()])){
                $config->setRights($checks[$config->getId()]);
            }
            $em->persist($config);
        }

        $em->flush();

        return $this->redirect($this->generateUrl('claro_workspace_rights', array('workspaceId' => $workspaceId)));
    }

    /**
     * If the option doens't exist in the database yet, it's created here.
     *
     * @param integer $workspaceId
     * @param integer $widgetId
     * @param integer $displayConfigId (the displayConfig defined by the administrator)
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function invertVisibleWidgetAction($workspaceId, $widgetId, $displayConfigId)
    {
        $em = $this->getDoctrine()->getEntityManager();
        $workspace = $em->getRepository(self::ABSTRACT_WS_CLASS)->find($workspaceId);
        $widget = $em->getRepository('ClarolineCoreBundle:Widget\Widget')->find($widgetId);

        $displayConfig = $em
            ->getRepository('ClarolineCoreBundle:Widget\DisplayConfig')
            ->findOneBy(array('workspace' => $workspace, 'widget' => $widget));

        if ($displayConfig == null){
            $displayConfig = new DisplayConfig();
            $baseConfig = $em->getRepository('ClarolineCoreBundle:Widget\DisplayConfig')->find($displayConfigId);
            $displayConfig->setParent($baseConfig);
            $displayConfig->setWidget($widget);
            $displayConfig->setWorkspace($workspace);
            $displayConfig->setVisible($baseConfig->isVisible());
            $displayConfig->setLock(true);
            $displayConfig->setDesktop(false);
            $displayConfig->invertVisible();
        } else {
            $displayConfig->invertVisible();
        }

        $em->persist($displayConfig);
        $em->flush();

        return new Response('success');
    }

    /**
     * Throws a ConfigureWidgetEvent
     *
     * @param integer $workspaceId
     * @param integer $widgetId
     *
     * @return Response
     */
    public function configureWidgetAction($workspaceId, $widgetId)
    {
         $em = $this->get('doctrine.orm.entity_manager');
         $workspace = $em->getRepository('ClarolineCoreBundle:Workspace\AbstractWorkspace')->find($workspaceId);
         $widget = $em->getRepository('ClarolineCoreBundle:Widget\Widget')->find($widgetId);
         $event = new ConfigureWidgetWorkspaceEvent($workspace);
         $eventName = strtolower("widget_{$widget->getName()}_configuration_workspace");
         $this->get('event_dispatcher')->dispatch($eventName, $event);

         if ($event->getContent() !== ''){
            return $this->render('ClarolineCoreBundle:Workspace:tools\widget_configuration.html.twig',
                array('content' => $event->getContent(), 'workspace' => $workspace));
         } else {
             throw new \Exception("event $eventName didn't return any Response");
         }
    }

    /*******************/
    /* PRIVATE METHODS */
    /*******************/

    private function checkRegistration($workspace)
    {
        $authorization = false;

        foreach ($workspace->getWorkspaceRoles() as $role) {
            if ($this->get('security.context')->isGranted($role->getName())) {
                $authorization = true;
            }
        }

        if ($authorization === false) {
            throw new AccessDeniedHttpException();
        }
    }
}