<script setup lang="ts">
import VueApexCharts from 'vue3-apexcharts/core'
import type { ApexOptions } from 'apexcharts'
import 'apexcharts/line'
import 'apexcharts/features/keyboard'

const props = defineProps<{ data: { day: string, count: number }[] }>()
const colorMode = useColorMode()
const theme = computed(() => colorMode.value === 'dark' ? 'dark' : 'light')
const series = computed(() => [{
  name: 'Pesanan',
  data: props.data.map(({ day, count }) => ({ x: day, y: count })),
}])

const options = computed<ApexOptions>(() => ({
  chart: {
    background: 'transparent',
    foreColor: 'var(--ui-text-muted)',
    fontFamily: 'var(--font-sans)',
    toolbar: { show: false },
    zoom: { enabled: false },
    animations: {
      enabled: true,
      speed: 650,
      animateGradually: { enabled: false },
      dynamicAnimation: { enabled: true, speed: 450 },
    },
  },
  theme: { mode: theme.value },
  colors: ['var(--ui-chart-1)'],
  stroke: { curve: 'smooth', width: 3 },
  markers: { size: 0, hover: { size: 5 } },
  dataLabels: { enabled: false },
  grid: { borderColor: 'var(--ui-border)', strokeDashArray: 4 },
  xaxis: {
    type: 'category',
    title: { text: 'Hari' },
    axisBorder: { show: false },
    axisTicks: { show: false },
  },
  yaxis: { min: 0, decimalsInFloat: 0 },
  tooltip: { theme: theme.value, x: { formatter: value => `Hari ${value}` } },
}))
</script>

<template>
  <VueApexCharts type="line" :height="285" :options="options" :series="series" />
</template>
