@props(['status'])

{{--
  Status pill. The dot comes from the .gh-status ::before rule, and the label is
  always rendered as text — colour alone never carries the meaning (WCAG 1.4.1),
  which matters for the eight percent of male users with colour vision deficiency.
--}}

<span class="gh-status {{ $status->badgeClasses() }}">
    {{ $status->label() }}
</span>
