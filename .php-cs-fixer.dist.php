<?php

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__.'/cli/',
        __DIR__.'/tests/'
    ]);

$config = new PhpCsFixer\Config();
return $config
    ->setRiskyAllowed(true)
    ->setUsingCache(false)
    ->setRules([
        '@PSR12' => true,
        'list_syntax' => ['syntax' => 'short'],
        'multiline_comment_opening_closing' => true,
        'no_blank_lines_after_phpdoc' => true,
        'no_empty_comment' => true,
        'no_empty_phpdoc' => true,
        'no_empty_statement' => true,
        'no_extra_blank_lines' => true,
        'no_spaces_after_function_name' => true,
        'no_superfluous_elseif' => true,
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true],
        'no_unused_imports' => true,
        'no_useless_else' => true,
        'no_whitespace_before_comma_in_array' => true,
        'no_whitespace_in_blank_line' => true,
        'object_operator_without_whitespace' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha', 'imports_order' => ['class', 'function', 'const']],
        'php_unit_construct' => true,
        'php_unit_expectation' => true,
        'php_unit_mock' => ['target' => 'newest'],
        'php_unit_mock_short_will_return' => true,
        'php_unit_no_expectation_annotation' => true,
        'php_unit_test_case_static_method_calls' => ['call_type' => 'this'],
        'phpdoc_add_missing_param_annotation' => true,
        'phpdoc_line_span' => ['method' => 'multi', 'property' => 'multi'],
        'phpdoc_no_useless_inheritdoc' => true,
        'phpdoc_scalar' => true,
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_trim' => true,
        'phpdoc_trim_consecutive_blank_line_separation' => true,
        'phpdoc_types' => true,
        'phpdoc_var_annotation_correct_order' => true,
        'phpdoc_var_without_name' => true,
        'protected_to_private' => true,
        'return_assignment' => true,
        'short_scalar_cast' => true,
        'single_trait_insert_per_statement' => true,
        'standardize_not_equals' => true,
        'trim_array_spaces' => true,
        'visibility_required' => ['elements' => ['const']],
        'whitespace_after_comma_in_array' => true
    ])
    ->setFinder($finder);
