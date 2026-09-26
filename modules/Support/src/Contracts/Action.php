<?php

declare(strict_types=1);

namespace Modules\Support\Contracts;

/**
 * Marks a class as an Action: the single place a business operation lives (spec §3.4,
 * ADR-0007).
 *
 * Intentionally empty. Actions have different signatures, so there is nothing honest to put
 * in a shared method — what matters is the contract around them, which the architecture
 * tests enforce:
 *
 *  - an Action is final and has exactly one public entry point (handle or __invoke);
 *  - it accepts validated DTOs, never a Request;
 *  - it checks its own authorisation, so every caller is equally protected;
 *  - controllers, Filament resources, commands, jobs and webhook handlers only call Actions.
 *
 * The interface exists so Actions are discoverable by type rather than by folder name alone.
 */
interface Action {}
