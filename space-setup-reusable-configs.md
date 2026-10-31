# Reusable space setups

This guide shows how to build a library of reusable `space:setup` files and combine them per customer or project. It uses features described in detail in [space-setup-config.md](space-setup-config.md): `extends`, `when`, `inputs`, and `assert`.

## The problem

Demo and project spaces share most of their setup: the same components, datasources, roles, and workflows. Only a few things change per customer: a brand color, a banner text, an optional review process.

Copying one big config file per customer works for the first few spaces. After that, a fix in a shared component has to be repeated in every copy, and the copies drift apart.

## The solution

Split the setup into small files, one per topic, and keep them as a library. Each customer gets a short main file that:

- **reuses** the library files with `extends`,
- **changes** only what is different,
- **switches** optional parts on or off with `when`,
- **checks** the target space first with `assert`.

A fix in a library file reaches every customer the next time their config is applied.

## 1. Start from an existing space

If a showcase space already has the components and datasources you want to reuse, export them instead of writing YAML by hand:

```bash
mkdir -p library
php bin/blokctl space:setup-export -S 295576087731079 --section=components -o library/components.yaml
php bin/blokctl space:setup-export -S 295576087731079 --section=datasources -o library/datasources.yaml --entries-dir=datasources
php bin/blokctl space:setup-export -S 295576087731079 --section=roles --section=workflows -o library/governance.yaml
```

The export replaces secret values (tokens, passwords) with `####YOURSECRET####` and lists them as warnings. Review the files, remove what you do not want to reuse, and commit them.

## 2. Organize the library

Keep one topic per file. Every file is a complete config (`version: 1`), so it can also be applied or validated on its own.

```
setups/
├── library/
│   ├── components.yaml        # promo-banner, hero-section, ...
│   ├── datasources.yaml       # banner-themes, countries
│   ├── datasources/
│   │   └── countries.csv      # entries, edited in a spreadsheet
│   └── governance.yaml        # roles and a review workflow
└── customers/
    ├── acme.yaml
    └── globex.yaml
```

File paths inside a library file, such as `entries_file: datasources/countries.csv`, are relative to that library file. They keep working when a customer file in another folder extends it.

A library file can extend another library file, for example a `full-demo.yaml` that combines the others.

## 3. Write a customer file

The customer file lists the library files in `extends` and adds only what is specific:

```yaml
# customers/acme.yaml
version: 1

extends:
  - ../library/datasources.yaml
  - ../library/components.yaml
  - ../library/governance.yaml

inputs:
  customer_name:
    default: Acme

datasources:
  - slug: banner-themes          # in the library: merged
    entries:
      - name: dark               # in the library: only the value changes
        value: "#003366"
      - name: brand              # new: added
        value: "#e30613"

components:
  ensure:
    - name: promo-banner         # in the library: only the color changes, the fields stay
      color: "#003366"

stories:
  insert_component:
    - slug: home
      target: { path: content.body, index: 1 }
      match: [title]
      component:
        component: promo-banner
        title: "Welcome ${{ inputs.customer_name }}"
        theme: brand
  publish:
    slugs: [home]
```

How the files combine:

- Files are merged in the order of `extends`, then the customer file. A later file wins.
- **Named items merge by name or slug**: components, fields, datasources, entries, roles, workflows, stages, and folders. To change one thing, repeat the name and only the setting you change.
- **Operations add up**: story inserts, updates, and tag assignments from all files run, library ones first.
- **Plain lists are replaced**: if the customer file sets `permissions` or `publish.slugs`, its list is used as a whole.
- Items cannot be removed through `extends`. If a customer must not get something, move it to its own library file and make that file optional (next step).

The full rules are in [Composing configurations with `extends`](space-setup-config.md#composing-configurations-with-extends).

## 4. Make parts optional

Use an `{path, when}` entry for library files that only some customers need:

```yaml
inputs:
  with_governance:
    default: true

extends:
  - ../library/datasources.yaml
  - ../library/components.yaml
  - path: ../library/governance.yaml
    when: ${{ inputs.with_governance }}
```

Choose the variant when you run the setup:

```bash
php bin/blokctl space:setup -S <space-id> --config customers/acme.yaml --set with_governance=false
```

`when` can also read an environment variable, for example `when: ${{ env.WITH_AI }}`. A variable that is not set counts as false, so it works as an on/off switch from the shell or `.env`. Inputs used in `when` must be declared in the customer file.

### Customer images

Customer logos and images can come from a URL passed as an input, and are used by name:

```yaml
inputs:
  logo_url:
    required: true

assets:
  upload_url:
    - name: customer_logo
      url: ${{ inputs.logo_url }}
      target_folder: Brand
      filename: customer-logo.svg

stories:
  insert_component:
    - slug: home
      target: { path: content.body, index: 1 }
      component:
        component: promo-banner
        title: "Welcome ${{ inputs.customer_name }}"
        logo:
          asset: { _ref: customer_logo }
```

```bash
php bin/blokctl space:setup -S <space-id> --config customers/acme.yaml --set logo_url=https://acme.example.com/logo.svg
```

## 5. Check the target space first

A customer file often relies on content it does not create, for example the `home` story where the banner is inserted. Add an `assert` section so the setup stops before any change when the space does not fit:

```yaml
assert:
  stories: [home]
  plan_at_least: premium
```

If something is missing, the run stops and lists every missing item in one message. Components, datasources, and other items that the merged config creates itself count as present.

## 6. Check, then apply

Look at the final configuration, then do a dry run, then apply:

```bash
# The merged result, as one file
php bin/blokctl space:setup-validate --config customers/acme.yaml --print-merged

# A variant
php bin/blokctl space:setup-validate --config customers/acme.yaml --print-merged --set with_governance=false

# What would change in the space
php bin/blokctl space:setup -S <space-id> --config customers/acme.yaml --dry-run

# Apply
php bin/blokctl space:setup -S <space-id> --config customers/acme.yaml
```

Applying the same config again changes nothing: existing items are compared and skipped.

## The result

- **One place per topic.** A component or datasource is defined once, in the library.
- **Short customer files** that show exactly what is specific to that customer.
- **Variants without copies**, chosen with `--set`.
- **Safe runs**: `assert` stops before any change, and every step can be previewed.

A complete working example is in [examples/composition/](examples/composition/): three library files and a customer file with an optional governance part.
