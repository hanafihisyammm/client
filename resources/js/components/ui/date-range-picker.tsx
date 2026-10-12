import * as React from "react"
import { format } from "date-fns"
import { Calendar as CalendarIcon, X } from "lucide-react"
import { type DateRange } from "react-day-picker"

import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { Calendar } from "@/components/ui/calendar"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"

interface DateRangePickerProps {
  /** Start of the range as "yyyy-MM-dd", or "" when not set */
  from: string
  /** End of the range as "yyyy-MM-dd", or "" when not set */
  to: string
  onChange: (range: { from: string; to: string }) => void
  placeholder?: string
  className?: string
  "aria-label"?: string
}

// "2026-10-12" must mean that day in the viewer's own timezone, not UTC midnight
function toDate(value: string): Date | undefined {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value)
  return match ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : undefined
}

const toValue = (date: Date | undefined) => (date ? format(date, "yyyy-MM-dd") : "")
const toLabel = (date: Date) => format(date, "d MMM yyyy")

/**
 * Pick a start and end date from a single calendar: click the first day, then the last.
 * Choosing only one day filters from that day onwards.
 */
export function DateRangePicker({
  from,
  to,
  onChange,
  placeholder = "Date range",
  className,
  "aria-label": ariaLabel,
}: DateRangePickerProps) {
  const [open, setOpen] = React.useState(false)

  const fromDate = toDate(from)
  const toDateValue = toDate(to)
  const selected: DateRange | undefined = fromDate ? { from: fromDate, to: toDateValue } : undefined

  const handleSelect = (range: DateRange | undefined) => {
    onChange({ from: toValue(range?.from), to: toValue(range?.to) })

    // Close once both ends are chosen and they are different days
    if (range?.from && range?.to && range.from.getTime() !== range.to.getTime()) {
      setOpen(false)
    }
  }

  let label: React.ReactNode = <span>{placeholder}</span>
  if (fromDate && toDateValue) {
    label = fromDate.getTime() === toDateValue.getTime() ? toLabel(fromDate) : `${toLabel(fromDate)} – ${toLabel(toDateValue)}`
  } else if (fromDate) {
    label = `From ${toLabel(fromDate)}`
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          aria-label={ariaLabel}
          variant="outline"
          className={cn("justify-start text-left font-normal", !fromDate && "text-muted-foreground", className)}
        >
          <CalendarIcon className="mr-2 h-4 w-4 shrink-0" />
          <span className="truncate">{label}</span>
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-auto p-0" align="start">
        <Calendar
          mode="range"
          selected={selected}
          onSelect={handleSelect}
          defaultMonth={fromDate ?? toDateValue}
          initialFocus
        />
        <div className="flex items-center justify-between border-t px-3 py-2 text-xs text-muted-foreground">
          <span>{fromDate && !toDateValue ? "Now pick the end date" : "Pick the start date, then the end date"}</span>
          {fromDate && (
            <button
              type="button"
              onClick={() => onChange({ from: "", to: "" })}
              className="inline-flex items-center gap-1 font-medium text-foreground hover:underline"
            >
              <X className="h-3 w-3" />
              Clear
            </button>
          )}
        </div>
      </PopoverContent>
    </Popover>
  )
}
