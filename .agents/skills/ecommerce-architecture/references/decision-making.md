# Architectural decision-making

Use this guide when a change crosses boundaries or could establish a lasting convention. The project decision register is at `docs/architecture/decisions.md` from the repository root.

## Evaluate the change

1. State the concrete operation, affected domain owner and authorized phase. Separate product behavior from a preferred implementation technique.
2. Check whether the accepted direction already answers the question. If not, compare the smallest practical alternatives and identify what evidence would distinguish them.
3. Trace the request through authorization, validation, business operation, persistence and response. Name the transaction owner and any external or asynchronous effects.
4. Check whether core can still operate with the optional addon absent. Identify the contract the addon needs rather than exposing an entire model or query builder.
5. Record consequential choices in the register with status, reasons and consequences. Keep uncertain behavior explicitly open.

## Boundary checks

- If a controller has stock, price or multi-model rules that an import would repeat, move that operation to a focused service. Do not create a service merely to wrap one ordinary Eloquent call.
- Apply A29's context-first layers: Presentation → Application → Domain; Infrastructure implements inward contracts. Domain/Application are framework-free; keep Eloquent records/mapping and Laravel broker/notifications in Infrastructure. Use [context-first DDD](../../../../docs/architecture/layered-ddd.md) for exact naming; do not revive superseded A28 layer-first/Eloquent-domain conventions.
- If a query needs reusable filtering, aggregation or careful visibility rules, consider a focused query object. Do not add a repository per model automatically.
- If a listener must run for a business operation to be correct, make the orchestration explicit. Decide whether it is part of the transaction rather than assuming every event is after commit.
- If optional code changes a result, publish a narrow synchronous contract with defined composition rules. If it reacts to a completed fact, prefer an event with defined commit timing.
- If React needs a business decision, compute an authorized server result or capability; do not duplicate authoritative rules in the component.

Do not select multi-tenancy, multi-vendor scope, a directory framework, a theme engine or generic entity abstractions just to make the architecture look extensible. Document the choice needed and defer it if the current slice does not depend on it.
