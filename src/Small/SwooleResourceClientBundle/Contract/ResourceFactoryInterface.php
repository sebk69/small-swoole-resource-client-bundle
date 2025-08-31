<?php

namespace Small\SwooleResourceClientBundle\Contract;

use Small\SwooleResourceClientBundle\Resource\Resource;

interface ResourceFactoryInterface
{
    
    public function createResource(string $name, int $timeout): Resource;
    public function getResource(string $name): Resource;

}