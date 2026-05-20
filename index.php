<?php
// index.php na RAIZ do projeto
// Serve como front controller quando o DocumentRoot aponta para a raiz

// Define o document root correto para o Symfony encontrar os assets
$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/public';

// Redireciona para o front controller real do Symfony
require_once __DIR__ . '/public/index.php';
