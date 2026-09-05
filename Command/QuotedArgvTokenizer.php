<?php


namespace MADIR\Command;

use SPTK\Core\Tokenizer;

class QuotedArgvTokenizer extends Tokenizer {

  protected array $regexpRules = [
    ['type' => 'QARGV',  'regexp' => '/^[^\\\\"]+/'],
    ['type' => 'QARGV',  'regexp' => '/^\\\\./']
  ];

}

(new QuotedArgvTokenizer)->initialize();
