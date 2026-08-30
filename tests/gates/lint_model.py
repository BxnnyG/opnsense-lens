#!/usr/bin/env python3
"""The model-XML conventions from opnsense/core's Mk/lint.mk, lint-model.

Transcribed rather than reinvented: every check below is one of the
`xmllint --xpath` expressions that target runs, in its order, with its wording.
Driven through xmllint for the same reason - a Python XPath library would be a
second implementation of the thing being checked.

Upstream prints these and does not fail on them, so neither does this.
"""

import subprocess
import sys
from pathlib import Path

# (xpath, message) - the message is appended to the offending element, as upstream does
CHECKS = [
    ('//*[@type and not(@type="ArrayField") and (not(Required) or Required="N") and Default]',
     'has a spurious default value set'),
    ('//*[@type and not(@type="ArrayField") and Default=""]',
     'has an empty default value set'),
    ('//*[@type and not(@type="ArrayField") and BlankDesc="None"]',
     'blank description is the default'),
    ('//*[@type and not(@type="ArrayField") and BlankDesc and Required="Y"]',
     'blank description not applicable on required field'),
    ('//*[@type and not(@type="ArrayField") and BlankDesc and Multiple="Y"]',
     'blank description not applicable on multiple field'),
    ('//*[@type and not(@type="ArrayField") and Multiple="N"]',
     'Multiple=N is the default'),
    ('//*[@type and not(@type="ArrayField") and Required="N"]',
     'Required=N is the default'),
    ('//*[@type and not(@type="ArrayField") and OptionValues[default[not(@value)]'
     ' or multiple[not(@value)] or required[not(@value)]]]',
     'option element default/multiple/required without value attribute'),
    ('//*[@type="CSVListField" and Mask and (not(MaskPerItem) or MaskPerItem=N)]',
     'uses Mask regex with MaskPerItem=N'),
    ('//*[@type="CSVListField" and not(Mask)]',
     'does not specify a Mask regex'),
    ('//ValidationMessage[not(substring(., string-length(.), 1) = ".")]',
     'does not end with a dot'),
    ('//OptionValues/*[(name() = text() and not(@value)) or @value = text()]',
     'option key and value are the same'),
]

# these two conventions are checked per field type by upstream's inner for-loop
LIST_TYPES = [
    '.\\AliasesField', '.\\DomainIPField', 'HostnameField', 'IPPortField',
    'NetworkField', 'MacAddressField', '.\\RangeAddressField',
]
LIST_CHECKS = [
    ('FieldSeparator=","', 'FieldSeparator=, is the default'),
    ('AsList="N"', 'AsList=N is the default'),
]


def xpath(model, expression):
    """Return the matching lines, or [] - a no-match is xmllint exit 10, not an error."""
    proc = subprocess.run(
        ['xmllint', model, '--xpath', expression],
        capture_output=True, text=True,
    )
    return [line for line in proc.stdout.splitlines() if line.startswith('<')]


def description_is_one_line(model):
    proc = subprocess.run(
        ['xmllint', model, '--xpath', '/model/description'],
        capture_output=True, text=True,
    )
    return len(proc.stdout.splitlines()) == 1


def main():
    plugin = Path(sys.argv[1] if len(sys.argv) > 1 else '.')
    root = plugin / 'src/opnsense/mvc/app/models'
    if not root.is_dir():
        print('no models directory')
        return 0

    # upstream: find <models> -depth 3 -name "*.xml", i.e. Vendor/Module/Model.xml
    models = sorted(str(p) for p in root.glob('*/*/*.xml'))
    findings = 0

    for model in models:
        name = model[len(str(plugin)) + 1:]
        if not description_is_one_line(model):
            print(f'{name}: <description/> is not on a single line or missing')
            findings += 1
        for expression, message in CHECKS:
            for hit in xpath(model, expression):
                print(f'{name}: {hit} {message}')
                findings += 1
        for field_type in LIST_TYPES:
            for predicate, message in LIST_CHECKS:
                expression = f'//*[@type="{field_type}" and {predicate}]'
                for hit in xpath(model, expression):
                    print(f'{name}: {hit} {message}')
                    findings += 1
        # upstream greps this one rather than using xpath
        for line in Path(model).read_text().splitlines():
            if '<ValidationMessage>' in line:
                text = line.split('<ValidationMessage>', 1)[1]
                if text[:1].islower() or text[:1] == ' ':
                    print(f'{name}: {line.strip()} does not start with an uppercase letter')
                    findings += 1

    print(f'models checked: {len(models)}, convention findings: {findings}')
    return 0


if __name__ == '__main__':
    sys.exit(main())
