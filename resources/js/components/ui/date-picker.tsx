import * as React from "react"
import { format, isAfter, startOfDay } from "date-fns"
import { Calendar as CalendarIcon } from "lucide-react"

import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { Calendar } from "@/components/ui/calendar"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"

interface DatePickerProps {
  value?: string | Date | null
  onChange: (date: string) => void
  placeholder?: string
  disabled?: boolean
  minDate?: Date
  /** Latest date that can be picked. No limit when left out. */
  maxDate?: Date
  /** How the chosen date is shown on the button (date-fns format). */
  displayFormat?: string
  className?: string
  id?: string
  "aria-label"?: string
}

/** Earliest date for pickers that must allow the past, such as date filters. */
export const ANY_PAST_DATE = new Date(2000, 0, 1)

// "2026-10-12" must mean that day in the viewer's own timezone, not UTC midnight
function toDate(value: string | Date): Date {
  if (typeof value === "string") {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value)
    if (match) return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
  }
  return new Date(value)
}

export function DatePicker({
  value,
  onChange,
  placeholder = "Pick a date",
  disabled = false,
  minDate,
  maxDate,
  displayFormat = "PPP",
  className,
  id,
  "aria-label": ariaLabel
}: DatePickerProps) {
  const [date, setDate] = React.useState<Date | undefined>(
    value ? toDate(value) : undefined
  )

  // Default min date to today if not provided
  const minimumDate = minDate || startOfDay(new Date())

  const handleDateSelect = (selectedDate: Date | undefined) => {
    if (selectedDate) {
      // Validate that the selected date is not before minimum date
      const notTooEarly = isAfter(selectedDate, minimumDate) || selectedDate.getTime() === minimumDate.getTime()
      const notTooLate = !maxDate || !isAfter(startOfDay(selectedDate), startOfDay(maxDate))
      if (notTooEarly && notTooLate) {
        setDate(selectedDate)
        onChange(format(selectedDate, "yyyy-MM-dd"))
      }
    } else {
      setDate(undefined)
      onChange("")
    }
  }

  React.useEffect(() => {
    if (value) {
      setDate(toDate(value))
    } else {
      setDate(undefined)
    }
  }, [value])

  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button
          id={id}
          aria-label={ariaLabel}
          variant="outline"
          className={cn(
            "w-full justify-start text-left font-normal",
            !date && "text-muted-foreground",
            className
          )}
          disabled={disabled}
        >
          <CalendarIcon className="mr-2 h-4 w-4" />
          {date ? format(date, displayFormat) : <span>{placeholder}</span>}
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-auto p-0" align="start">
        <Calendar
          mode="single"
          selected={date}
          onSelect={handleDateSelect}
          disabled={(date) => isAfter(minimumDate, date) || (!!maxDate && isAfter(startOfDay(date), startOfDay(maxDate)))}
          initialFocus
        />
      </PopoverContent>
    </Popover>
  )
}