<?php


namespace MADIR\Command;

class QuotedArgvTokenizer extends \SPTK\Tokenizer {

  protected $regexpRules = [
    ['type' => 'QARGV',  'regexp' => '/^[^\\\\"]+/'],
    ['type' => 'QARGV',  'regexp' => '/^\\\\./']
  ];

}

(new QuotedArgvTokenizer)->initialize();