# ADR-037: per-OpenSpec-change register fragments are merged here at load.
# Each change adds its own <change>.json (OpenAPI components.schemas/paths) instead
# of editing shillinq_register.json — concurrent builds never conflict.
