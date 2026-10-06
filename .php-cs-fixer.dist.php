<?php
$finder=PhpCsFixer\Finder::create()->in([__DIR__.'/app',__DIR__.'/config',__DIR__.'/scripts',__DIR__.'/tests'])->name('*.php');
return (new PhpCsFixer\Config())->setRules(['@PSR12'=>true,'single_quote'=>true,'array_syntax'=>['syntax'=>'short']])->setFinder($finder)->setUsingCache(false);
