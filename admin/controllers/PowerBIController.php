<?php
// admin/controllers/PowerBIController.php

class PowerBIController extends Controller {

    public function index() {
        
        $this->render('powerbi/index', [], 'admin');
    }
}