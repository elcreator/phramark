<?php

namespace Phramark\Benchmark\Controllers;

use Backend\Classes\Controller;
use Backend\Facades\BackendMenu;

/**
 * Standard Winter backend CRUD: the Form behavior saves through an AJAX
 * handler (onSave, sent with the X-Winter-Request-Handler header), the List
 * behavior renders the index.
 */
class Pages extends Controller
{
    public $implement = [
        \Backend\Behaviors\FormController::class,
        \Backend\Behaviors\ListController::class,
    ];

    public $formConfig = 'config_form.yaml';
    public $listConfig = 'config_list.yaml';

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('Phramark.Benchmark', 'phramark');
    }
}
