# Business rules — bank status and leasing information

**Status:** AUTHORITATIVE for manual business tests and future changes.  
**Scope:** WooCommerce UniCredit module (`mtunicredit`) and its Control Panel / SmartUCF integration surface.  
**Language note:** Public status *strings* below are Bulgarian and must not be changed. English terms are used for architecture vocabulary only.

This document answers:

- Which public (standard) bank statuses exist?
- When is each used?
- What happens with later SmartUCF statuses?
- What is an internal/service lifecycle state?
- Where may diagnostic/debug information appear?
- What does the standard customer/business-facing leasing block contain?
- What information is forbidden in normal customer/business UI?

---

## Terminology

Use these terms consistently:

| Term | Meaning |
| --- | --- |
| **Standard bank status** | One of the four public bank statuses, or a later raw SmartUCF status shown as bank status. |
| **Internal/service lifecycle state** | Technical lifecycle, retry, transport, correlation, or diagnostic state. Not a standard bank status. |
| **Customer/business-facing leasing information** | The standardized leasing field block shown on approved customer/admin business surfaces. |
| **Diagnostic/debug information** | Support/developer-only data (debug journal, logs, specialized panels). |
| **Process 1** | Banking flow that requires successful SmartUCF create/send for the success standard status. |
| **Process 2** | Banking flow whose success standard status is after successful shop + CP create (SmartUCF proof not required for that status). |
| **Control Panel / CP** | UniCredit Control Panel. |
| **SmartUCF** | UniCredit SmartUCF financing channel. |

Do **not** call an internal/service lifecycle state a “bank status”, except when explicitly contrasting that it is *not* a standard public bank status.

---

## 1. Two classes of statuses / states

### 1.1. Standard bank statuses

Standard bank statuses may be visible to:

- store administrators;
- store customers, when the corresponding UI shows them;
- bank users in Control Panel;
- standard email messages;
- any other officially designated bank-status surfaces.

Until a later status is received from SmartUCF, there are **exactly four** allowed standard bank statuses.

The following strings are **AUTHORITATIVE** and must not be renamed:

#### A. `Неуспешно изпратен Банка - КП`

Used when:

- the order was created in the shop;
- the order was **not** successfully created and visible in Control Panel;
- the order was **not** successfully created/sent in SmartUCF.

This is the standard public bank status for failure **before** successful CP create.

#### B. `Неуспешно изпратен Банка - SmartUCF`

Used when:

- the order was created successfully in the shop;
- the order was created successfully and is visible in Control Panel;
- create/send to SmartUCF was **not** successful.

This is the standard public bank status for failure **after** successful CP create, but unsuccessful SmartUCF create/send.

#### C. `Изпратен Банка - Процес 1`

Used when:

- the order was created successfully in the shop;
- the order was created successfully in Control Panel;
- the order was created/sent successfully in SmartUCF;
- Process 1 was used.

#### D. `Изпратен Банка - Процес 2`

Used when:

- the order was created successfully in the shop;
- the order was created successfully in Control Panel;
- Process 2 was used.

For this standard status, proof that the order was already created or sent to SmartUCF is **not** required.

### 1.2. Later statuses from SmartUCF

After the initial bank status, Control Panel may request a current status from SmartUCF:

- via a manual request from CP; or
- via CP’s automatic periodic/daily check.

SmartUCF may return a new bank status.

There is **no** guaranteed complete mapping of all possible SmartUCF values.

**Rule (exact):**

```text
Статусът се записва и показва точно така, както е върнат от SmartUCF.
```

- Do **not** rename it.
- Do **not** normalize it into a predefined list.
- Do **not** alter its text.

This rule applies to the shop and to CP.

### 1.3. Internal/service lifecycle states (служебни статуси / под-статуси)

Besides standard bank statuses, the system may have internal lifecycle, retry, diagnostic, or technical states.

Examples may include:

- pending;
- retryable;
- timeout;
- outcome unknown;
- submitting;
- created;
- failed stage;
- subsystem;
- error class;
- correlation;
- lifecycle state;
- transport state;
- retry state;
- internal progression state;
- similar technical values.

These are **internal/service** states.

They are **not** standard bank statuses.

They must **not** be shown as bank status to:

- the customer;
- the standard admin order UI;
- standard emails;
- the CP order list;
- other normal business screens.

Internal/service information may be visible **only** on predetermined diagnostic places, for example:

- SmartUCF debug information in CP;
- debug information fetched from the shop;
- specialized developer/admin diagnostic panels;
- application/module logs;
- other explicitly designated service places.

Never mix an internal/service lifecycle state with a standard bank status.

---

## 2. Allowed locations for a standard bank status

A standard bank status may be shown only on officially designated places.

Current set:

1. Shop — order list, in a dedicated bank-status column.
2. Shop — single order view, in the dedicated leasing/bank panel.
3. Standard email messages, when they are designed to include bank status.
4. Control Panel — order list / order table.
5. Other previously created and explicitly agreed bank-status surfaces.

If a UI field is labeled as bank status (“банков статус” / “Статус към банката”), it must show:

- one of the four standard bank statuses; **or**
- a later raw status returned by SmartUCF.

It must **not** show internal lifecycle/debug values.

---

## 3. Customer/business-facing leasing information

### 3.1. Purpose and placement

There is a standardized customer/business-facing leasing information block.

It is used on predetermined places, for example:

- the additional leasing panel in order view;
- Thank You / order confirmation, when designed to include it;
- standard email messages;
- specially designated reports;
- other previously agreed customer/admin-facing places.

This standard block may be adapted only in predetermined business cases.

Example:

- for Process 2, a second phone may be added;
- for Process 2, EGN may be added;
- but only on places where that is explicitly allowed.

Do **not** automatically extend this block with internal lifecycle/debug data.

### 3.2. Standard field set

Base customer/business-facing block (values are examples; the field set is authoritative):

```text
Статус към банката    Изтекло време за регистрация
КП поръчка (ID)       329
КП shop order_id      920
Срок (месеци)         12
КОП                    POS COM 50
Първоначална вноска   0.00
Сума на заема         1000.00
Месечна вноска        97.49
Обща дължима сума     1169.88
ГЛП / ГПР             30.00% / 34.50%
```

Documented field labels:

1. `Статус към банката`
2. `КП поръчка (ID)`
3. `КП shop order_id`
4. `Срок (месеци)`
5. `КОП`
6. `Първоначална вноска`
7. `Сума на заема`
8. `Месечна вноска`
9. `Обща дължима сума`
10. `ГЛП / ГПР`

### 3.3. Forbidden service information in the standard leasing block

Except on predetermined diagnostic places, the standard leasing panel must **not** show technical information such as:

```text
КП създаване
Последна грешка (категория)
Подсистема
Час на грешката
Повторение възможно
Корелация
lifecycle state
retry state
HTTP/transport classification
timeout/network details
internal error class
internal CP/SmartUCF stage
```

or any other information that:

- describes internal architecture;
- exposes technical lifecycle;
- exposes retry mechanisms;
- reveals the business/technical integration model;
- is intended only for developer/support diagnostics.

That information is useful internally, but is **not** customer-facing and must not appear in the normal order UI.

---

## 4. Privacy / business-model rule

Core principle:

```text
Customer-facing и normal business-facing UI трябва да съдържа само информацията,
необходима за поръчката и лизинга.
```

It must not expose service details about:

- internal Shop → CP → SmartUCF communication;
- retry mechanisms;
- timeout / outcome-unknown mechanisms;
- internal state machines;
- internal correlation / error classification;
- technical architectural details.

---

## 5. Implementation keys (non-normative mapping aid)

Machine keys used in the shop module for the four standard statuses (for implementers; public UI must use the AUTHORITATIVE Bulgarian strings above):

| Standard bank status (public string) | Typical machine key |
| --- | --- |
| `Неуспешно изпратен Банка - КП` | `bank_send_failed_cp` |
| `Неуспешно изпратен Банка - SmartUCF` | `bank_send_failed_smartucf` |
| `Изпратен Банка - Процес 1` | `bank_sent_process1` |
| `Изпратен Банка - Процес 2` | `bank_sent_process2` |

Later SmartUCF statuses: store and display the returned text/value as received (no invented mapping table in this document).

---

## 6. Document history

- Introduced as the canonical business-rules source for bank status and leasing presentation in the Woo module.
- Supersedes any prior informal notes that treated internal lifecycle values as public bank statuses, invented SmartUCF status mappings, or allowed diagnostic fields in the standard leasing block.
