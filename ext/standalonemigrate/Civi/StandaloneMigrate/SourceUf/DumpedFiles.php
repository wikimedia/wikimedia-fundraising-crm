<?php

namespace Civi\StandaloneMigrate\SourceUf;

class DumpedFiles extends SourceUf {

  protected string $sourceDirectory;

  protected ?array $users = null;

  public function __construct(string $sourceDirectory) {
    $this->sourceDirectory = $sourceDirectory;
  }

  public function getRoles(): array {
    $json = file_get_contents($this->sourceDirectory . '/roles.json');
    return json_decode($json, true);
  }

  public function getUser(int $ufId): array {
    if ($this->users === null) {
      $json = file_get_contents($this->sourceDirectory . '/users.json');
      $this->users = json_decode($json, true);
    }
    return $this->users[$ufId];
  }
}
