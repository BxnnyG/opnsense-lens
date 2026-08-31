# Stage 06 — The operator's own words (Plan)

> Systems: S1 · Depends on: stages 5 and 16 · Built 2026-08-30
>
> Entry rule (PROCESS): traces to DESIGN §S1 ("operator-owned fields live beside
> the observed ones and are never overwritten by an observation"), §4.29 and
> §4.30.

## 1. The problem from the user's point of view

Box 2 lists thirty devices called `Proxmox Server Solutions GmbH 1B5816`. That
is what the hardware says, and it is the best Lens can do on its own — the box
runs no DHCP server that hands out names, so nothing on the network announces
one. It is also useless: you cannot tell which of the thirty is the mail server.

The name a device has is often only in your head. This stage puts it in the
plugin: a name, a kind, tags and a note, kept against the MAC, surviving a new
address, a new lease, and dnsmasq deciding to call it something else tomorrow.

## 2. What gets built

1. **Schema v4 — `device_label`.** A separate table on purpose. Nothing in the
   collector writes it and nothing observed is stored in it, so the two can
   never quietly merge (§4.30).
2. **`collect.py label --mac --fields`**, the plugin's first write. The fields
   arrive base64url-encoded, because a device name is free text a person typed
   and it travels through configd's parameter list.
3. **`DeviceReport`** prefers the chosen name, marks it *you named it*, and lets
   a chosen kind override the inferred icon — which is the promise §4.29 made.
4. **A pencil on every row**, opening a small editor. Saving re-reads the list
   rather than patching the row, because the name changes what the search
   matches.

## 3. Retention and the purge promise

Both were extended before this shipped, and a test found both:

- **A label leaves with the device it describes.** It is a MAC address plus what
  a person wrote about it. An orphaned row would outlive the retention it was
  promised under, invisibly, because nothing else joins to that table.
- **`purge` empties it.** S14 says everything, and a name someone typed is the
  most personal thing in the store.

## 4. Test strategy

| Failure mode | Test |
|---|---|
| an observation overwriting a chosen name | `testAnObservationNeverWritesOverTheOperatorsName`, `test_a_label_survives_every_later_observation` |
| a chosen kind still shown as a guess | `testAChosenKindOverridesTheGuessAndStopsBeingAGuess` |
| a kind from a newer version blanking the icon | `testAKindThisVersionDoesNotKnowFallsBackRatherThanBlanking` |
| a name with quotes or a semicolon breaking the configd hop | `test_a_label_survives_the_trip_through_configd_with_its_punctuation` |
| junk stored instead of refused | `test_unreadable_label_fields_are_refused_rather_than_stored_as_junk` |
| a label outliving retention, or surviving a purge | `test_a_label_leaves_with_the_device_it_describes`, `test_purge_takes_the_labels_too` |
| "no name of my own" confused with an empty name | `test_clearing_every_field_removes_the_label_rather_than_storing_blanks` |

## 5. Risks & the way back

- **Thirty devices is thirty typing jobs.** Tags make it bearable and grouping
  (BACKLOG #19) will make it worth it. A bulk edit is not in this stage.
- **The write path is new.** It writes only to Lens's own SQLite file; the
  firewall's configuration is untouched (§4.9 still holds). The one-click fixes
  in BACKLOG #18 are the case that will really test that line.
- **Way back:** schema v4 only adds a table. Dropping the pencil from the view
  leaves everything observed exactly as it was.
