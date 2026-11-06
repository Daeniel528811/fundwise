<?php
return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setUsingCache(false)
    ->setRules([
        'binary_operator_spaces' => true,
        'braces_position' => ['allow_single_line_anonymous_functions' => false],
        'cast_spaces' => true,
        'concat_space' => ['spacing' => 'one'],
        'control_structure_braces' => true,
        'control_structure_continuation_position' => true,
        'function_declaration' => true,
        'indentation_type' => true,
        'no_multiple_statements_per_line' => true,
        'line_ending' => true,
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'no_spaces_around_offset' => true,
        'return_type_declaration' => true,
        'single_space_around_construct' => true,
        'space_after_semicolon' => true,
        'statement_indentation' => true,
        'type_declaration_spaces' => true,
        'ternary_operator_spaces' => true,
        'unary_operator_spaces' => true,
        'whitespace_after_comma_in_array' => true,
    ])
    ->setFinder(PhpCsFixer\Finder::create()
        ->in(__DIR__)
        ->exclude(['.git', 'vendor', 'node_modules'])
        ->notName('config.php')
        ->name('*.php'));
