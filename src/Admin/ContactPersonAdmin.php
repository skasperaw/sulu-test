<?php

declare(strict_types=1);

namespace App\Admin;

use Sulu\Bundle\AdminBundle\Admin\Admin;
use Sulu\Bundle\AdminBundle\Admin\Navigation\NavigationItem;
use Sulu\Bundle\AdminBundle\Admin\Navigation\NavigationItemCollection;
use Sulu\Bundle\AdminBundle\Admin\View\ToolbarAction;
use Sulu\Bundle\AdminBundle\Admin\View\ViewBuilderFactoryInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ViewCollection;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;

class ContactPersonAdmin extends Admin
{
    public const RESOURCE_KEY = 'contact_persons';
    public const LIST_KEY = 'contact_persons';
    public const FORM_KEY = 'contact_person';
    public const LIST_VIEW = 'app.contact_person.list';
    public const ADD_FORM_VIEW = 'app.contact_person.add_form';
    public const EDIT_FORM_VIEW = 'app.contact_person.edit_form';
    public const SECURITY_CONTEXT = 'app.contact_persons';

    public function __construct(
        private readonly ViewBuilderFactoryInterface $viewBuilderFactory,
        private readonly SecurityCheckerInterface $securityChecker,
    ) {
    }

    public const DATA_OBJECTS_NAVIGATION_ITEM = 'app.data_objects';

    public function configureNavigationItems(NavigationItemCollection $navigationItemCollection): void
    {
        if (!$this->securityChecker->hasPermission(self::SECURITY_CONTEXT, PermissionTypes::VIEW)) {
            return;
        }

        if (!$navigationItemCollection->has(self::DATA_OBJECTS_NAVIGATION_ITEM)) {
            $dataObjects = new NavigationItem(self::DATA_OBJECTS_NAVIGATION_ITEM);
            $dataObjects->setPosition(45);
            $dataObjects->setIcon('su-storage');
            $navigationItemCollection->add($dataObjects);
        }

        $item = new NavigationItem('app.contact_persons');
        $item->setPosition(10);
        $item->setView(self::LIST_VIEW);

        $navigationItemCollection->get(self::DATA_OBJECTS_NAVIGATION_ITEM)->addChild($item);
    }

    public function configureViews(ViewCollection $viewCollection): void
    {
        if (!$this->securityChecker->hasPermission(self::SECURITY_CONTEXT, PermissionTypes::VIEW)) {
            return;
        }

        $listToolbarActions = [new ToolbarAction('sulu_admin.export')];
        $formToolbarActions = [];

        if ($this->securityChecker->hasPermission(self::SECURITY_CONTEXT, PermissionTypes::ADD)) {
            $listToolbarActions[] = new ToolbarAction('sulu_admin.add');
        }

        if ($this->securityChecker->hasPermission(self::SECURITY_CONTEXT, PermissionTypes::EDIT)
            || $this->securityChecker->hasPermission(self::SECURITY_CONTEXT, PermissionTypes::ADD)) {
            $formToolbarActions[] = new ToolbarAction('sulu_admin.save');
        }

        if ($this->securityChecker->hasPermission(self::SECURITY_CONTEXT, PermissionTypes::DELETE)) {
            $listToolbarActions[] = new ToolbarAction('sulu_admin.delete');
            $formToolbarActions[] = new ToolbarAction('sulu_admin.delete');
        }

        $listViewBuilder = $this->viewBuilderFactory
            ->createListViewBuilder(self::LIST_VIEW, '/contact-persons')
            ->setResourceKey(self::RESOURCE_KEY)
            ->setListKey(self::LIST_KEY)
            ->setTitle('app.contact_persons')
            ->addListAdapters(['table'])
            ->enableSearching()
            ->addToolbarActions($listToolbarActions);

        if ($this->securityChecker->hasPermission(self::SECURITY_CONTEXT, PermissionTypes::ADD)) {
            $listViewBuilder->setAddView(self::ADD_FORM_VIEW);
        }

        if ($this->securityChecker->hasPermission(self::SECURITY_CONTEXT, PermissionTypes::EDIT)) {
            $listViewBuilder->setEditView(self::EDIT_FORM_VIEW);
        }

        $viewCollection->add($listViewBuilder);

        $viewCollection->add(
            $this->viewBuilderFactory
                ->createResourceTabViewBuilder(self::ADD_FORM_VIEW, '/contact-persons/add')
                ->setResourceKey(self::RESOURCE_KEY)
                ->setBackView(self::LIST_VIEW)
        );

        $viewCollection->add(
            $this->viewBuilderFactory
                ->createFormViewBuilder(self::ADD_FORM_VIEW . '.details', '/details')
                ->setResourceKey(self::RESOURCE_KEY)
                ->setFormKey(self::FORM_KEY)
                ->setTabTitle('sulu_admin.details')
                ->setEditView(self::EDIT_FORM_VIEW)
                ->addToolbarActions($formToolbarActions)
                ->setParent(self::ADD_FORM_VIEW)
        );

        $viewCollection->add(
            $this->viewBuilderFactory
                ->createResourceTabViewBuilder(self::EDIT_FORM_VIEW, '/contact-persons/:id')
                ->setResourceKey(self::RESOURCE_KEY)
                ->setBackView(self::LIST_VIEW)
                ->setTitleProperty('fullName')
        );

        $viewCollection->add(
            $this->viewBuilderFactory
                ->createFormViewBuilder(self::EDIT_FORM_VIEW . '.details', '/details')
                ->setResourceKey(self::RESOURCE_KEY)
                ->setFormKey(self::FORM_KEY)
                ->setTabTitle('sulu_admin.details')
                ->addToolbarActions($formToolbarActions)
                ->setParent(self::EDIT_FORM_VIEW)
        );
    }

    public function getSecurityContexts(): array
    {
        return [
            self::SULU_ADMIN_SECURITY_SYSTEM => [
                'Settings' => [
                    self::SECURITY_CONTEXT => [
                        PermissionTypes::VIEW,
                        PermissionTypes::ADD,
                        PermissionTypes::EDIT,
                        PermissionTypes::DELETE,
                    ],
                ],
            ],
        ];
    }
}
