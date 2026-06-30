# DEVELOPMENT RULES

---

# 1. TEST-FIRST DEVELOPMENT (MANDATORY)

For EVERY feature:

1. write test
2. run test (fail)
3. implement
4. test must pass

---

# 2. TEST TYPES REQUIRED

* unit tests (services)
* integration tests (API)
* validation tests

---

# 3. FILE LIMITS

* max 1200 lines
* split before reaching limit

---

# 4. NAMING RULES

* variables: snake_case (PHP)
* methods: camelCase
* classes: PascalCase

---

# 5. CODE STYLE

* no magic numbers
* no hardcoded values
* everything configurable

---

# 6. ERROR HANDLING

* no silent failures
* always return structured error

---

# 7. LOGGING

* all critical operations logged
* include:

  * user_id
  * action
  * timestamp

---

# 8. DATABASE RULES

* ALWAYS use prepared statements
* NEVER build raw SQL strings

---

# 9. VALIDATION

* ALWAYS backend validation
* frontend validation = optional

---

# 10. PERFORMANCE

* batch operations required
* avoid loops with DB queries

---

# 11. COMMIT STYLE (if used)

* small commits
* one feature per commit

---

# 12. FORBIDDEN

* duplicate logic
* large monolithic files
* mixing concerns

---

END
