<?php

namespace Framework\Core;

/**
 * Interface IIdentity
 *
 * Represents a user identity with methods to retrieve the user's id and name.
 * Other methods can be added as needed to extend the identity functionality.
 *
 * @package Framework\Core
 */
interface IIdentity
{
    public function getName(): string;

    // Return the unique identifier for the identity (user id)
    public function getId(): int;
}
