# Coldplay Ticketing Platform

Architecture-first assignment repository for designing a high-scale concert ticketing platform that can handle massive fan traffic while preserving a seamless user experience.

## Problem Statement
Coldplay is coming to Mumbai. Design a ticketing platform that withstands millions of concurrent fans and keeps booking fast, fair, and reliable.

## Given Scope
- `The Booking Logic`: As a User, I want to book an event ticket.
- `The Feedback Loop`: As a User, I want to receive a confirmation email after booking.
- `The Infrastructure`: As a New Developer, I want to onboard and run the project easily.
- `The Quality Gate`: As a Maintainer, I want to ensure the code works before merging.
- `The Extensibility`: As a lead, I want to write logic such that we can create mobile applications using the same service.

## Repository Structure
- `docs/architecture.md` - End-to-end architecture and tradeoffs
- `docs/capacity-planning.md` - Throughput, QPS, and scaling math
- `docs/api-contracts.md` - Core APIs and request/response contracts
- `docs/failure-scenarios.md` - Chaos cases and mitigations
- `src/` - Optional reference implementation
