# Task: Logic and transactions

## Mission

Read the core write paths and find bugs: wrong conditions, broken state transitions, and multi-step writes that can leave partial state behind.

## Inputs

- The `all-routes` file of the Routes snapshot above — pick the write routes (POST, PUT, PATCH, DELETE) and follow them into controllers, actions and services
- Models with state columns (status, stock, balances)

## Checklist

- Transactions: multi-step writes without a transaction (a later step fails, earlier steps stay committed); several separate transactions for what the user sees as one operation; jobs, mails or events dispatched inside a transaction without `afterCommit`. Describe the concrete failure sequence. (`transaction-safety`)
- Logic bugs found by reading the code: wrong conditions, off-by-one, broken state transitions, rounding with money, timezone handling, unvalidated input reaching typed columns (500 instead of 422/404). (`logic-bug`)

Focus on the paths where a bug costs the most. Depth beats breadth.
