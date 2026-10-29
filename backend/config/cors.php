<?php
return [
 'paths'=>['api/*','sanctum/csrf-cookie','up'],
 'allowed_methods'=>['GET','POST','OPTIONS'],
 'allowed_origins'=>array_values(array_unique(array_filter([env('FRONTEND_URL','http://localhost:5173'), 'https://wisync-corp.github.io']))),
 'allowed_origins_patterns'=>[], 'allowed_headers'=>['*'],
 'exposed_headers'=>[], 'max_age'=>0, 'supports_credentials'=>false,
];
