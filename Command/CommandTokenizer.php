<?php

namespace MADIR\Command;

class CommandTokenizer extends \SPTK\Tokenizer {

  protected $contextSwitchers = [
    [
      'start' => '"',
      'end' => '"',
      'escape' => '\\',
      'escapeItself' => true,
      'tokenizer' => '\MADIR\Command\QuotedArgvTokenizer',
      'type' => 'QUOTED_ARGV'
    ]
  ];
  protected $regexpRules = [
    ['type' => 'AND', 'regexp' => '/^&&/'],
    ['type' => 'OR', 'regexp' => '/^\|\|/'],
    ['type' => 'COMMAND_SEPARATOR', 'regexp' => '/^;/'],
    ['type' => 'PARALLEL_SEPARATOR', 'regexp' => '/^&/'],
    ['type' => 'PIPE', 'regexp' => '/^\|/'],
    ['type' => 'REDIRECT_APPEND', 'regexp' => '/^>>/'],
    ['type' => 'REDIRECT_STDERR_STDOUT', 'regexp' => '/^2>1/'],
    ['type' => 'REDIRECT_STDERR', 'regexp' => '/^2>/'],
    ['type' => 'REDIRECT', 'regexp' => '/^>/'],
    ['type' => 'REDIRECT_INPUT', 'regexp' => '/^</'],
    ['type' => 'WHITESPACE', 'regexp' => '/^\s/'],
    ['type' => 'VARIABLE', 'regexp' => '/^\$[A-Za-z_][A-Za-z0-9_]*/'],
    ['type' => 'ARGV', 'regexp' => '/^[^&|;><\s]+/'],
    ['type' => 'UNKNOWN', 'regexp' => '/^.+/']
  ];

}

(new CommandTokenizer)->initialize();
